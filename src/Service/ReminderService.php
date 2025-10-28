<?php declare(strict_types=1);

namespace FoerdeClickCollect\Service;

use Doctrine\DBAL\Connection;
use FoerdeClickCollect\Event\PickupReminderEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\FlowDispatcher;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Style\SymfonyStyle;

class ReminderService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $orderRepository,
        private readonly FlowDispatcher $flowDispatcher,
        private readonly PickupConfigResolver $pickupConfigResolver,
    ) {
    }

    /**
     * Sends reminder emails for Click & Collect deliveries in 'ready' state within pickup window.
     * Returns number of emails sent. Throws \RuntimeException if required DB templates are missing.
     */
    public function sendReminders(?SymfonyStyle $io = null): int
    {
        $now = new \DateTimeImmutable('now');
        $context = Context::createDefaultContext();

        // Validate template exists
        $typeId = $this->connection->fetchOne(
            'SELECT id FROM mail_template_type WHERE technical_name = :name',
            ['name' => 'fb_click_collect.reminder']
        );
        if (!$typeId) {
            throw new \RuntimeException('Missing mail_template_type fb_click_collect.reminder. Run migrations or create the template type.');
        }
        $templateId = $this->connection->fetchOne(
            'SELECT id FROM mail_template WHERE mail_template_type_id = :typeId ORDER BY created_at DESC LIMIT 1',
            ['typeId' => $typeId]
        );
        if (!$templateId) {
            throw new \RuntimeException('No mail_template found for type fb_click_collect.reminder. Create a template in Admin.');
        }

        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT
    o.id              AS order_id,
    o.version_id      AS order_version_id,
    o.order_number,
    o.sales_channel_id,
    o.language_id,
    o.created_at      AS order_created,
    o.custom_fields   AS custom_fields,
    od.custom_fields  AS delivery_custom_fields,
    oc.email,
    CONCAT_WS(" ", oc.first_name, oc.last_name) AS customer_name,
    sm.technical_name AS shipping_tech
FROM order_delivery od
INNER JOIN `order` o ON o.id = od.order_id AND o.version_id = od.order_version_id
INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.version_id = o.version_id
INNER JOIN shipping_method sm ON sm.id = od.shipping_method_id
INNER JOIN state_machine_state sms ON sms.id = od.state_id
WHERE sm.technical_name = :tech
  AND sms.technical_name = :stateReady
  AND (
        o.custom_fields IS NULL
        OR JSON_EXTRACT(o.custom_fields, '$.foerde_cc_reminderSent') IS NULL
        OR JSON_EXTRACT(o.custom_fields, '$.foerde_cc_reminderSent') = false
  )
SQL,
            [
                'tech' => 'click_collect',
                'stateReady' => 'ready',
            ]
        );

        $sent = 0;
        foreach ($rows as $row) {
            $orderId = $row['order_id'];
            if (!\is_string($orderId) || $orderId === '') {
                continue;
            }

            $order = $this->loadOrder($orderId, $context);
            if (!$order instanceof OrderEntity) {
                continue;
            }

            $orderCreated = $row['order_created'] ? new \DateTimeImmutable((string) $row['order_created']) : null;
            if (!$orderCreated) {
                continue;
            }

            $orderCustomer = $order->getOrderCustomer();
            $email = (string) ($orderCustomer?->getEmail() ?? '');
            if ($email === '') {
                continue;
            }

            $salesChannelId = $order->getSalesChannelId();
            $languageId = $order->getLanguageId();
            $salesChannelIdHex = \is_string($salesChannelId) ? $salesChannelId : Uuid::fromBytesToHex((string) $row['sales_channel_id']);
            $languageIdHex = \is_string($languageId) ? $languageId : (is_string($row['language_id']) ? Uuid::fromBytesToHex($row['language_id']) : null);
            $orderNumber = (string) $order->getOrderNumber();

            $deliveryCustomFields = $this->decodeCustomFields($row['delivery_custom_fields'] ?? null);
            $snapshot = $this->pickupConfigResolver->extractSnapshotFromCustomFields($deliveryCustomFields)
                ?? $this->pickupConfigResolver->resolve($salesChannelIdHex ?? '', $languageIdHex ? Uuid::fromHexToBytes($languageIdHex) : null);

            $pickupWindowDays = $snapshot['pickupWindowDays'];
            $storeName = $snapshot['storeName'] !== '' ? $snapshot['storeName'] : 'Ihr Markt';
            $storeAddress = $snapshot['storeAddress'];
            $openingHoursCfg = $snapshot['openingHours'];

            $expiry = $orderCreated->modify('+' . $pickupWindowDays . ' days');
            if ($expiry < $now) {
                continue; // outside pickup window
            }

            try {
                $event = new PickupReminderEvent(
                    $context,
                    $order,
                    $salesChannelIdHex ?? '',
                    $snapshot,
                    $languageIdHex
                );
                $this->flowDispatcher->dispatch($event);

                $orderVersionId = $row['order_version_id'];
                if (is_string($orderId) && is_string($orderVersionId)) {
                    $this->connection->executeStatement(<<<'SQL'
UPDATE `order`
SET custom_fields = JSON_SET(COALESCE(custom_fields, JSON_OBJECT()),
    '$.foerde_cc_reminderSent', true,
    '$.foerde_cc_reminderSentAt', CAST(NOW() AS CHAR)
)
WHERE id = :id AND version_id = :vid
SQL,
                        ['id' => $orderId, 'vid' => $orderVersionId]
                    );
                }
                $sent++;
            } catch (\Throwable $e) {
                if ($io) {
                    $io->error(sprintf('Failed to send reminder for order #%s: %s', $orderNumber, $e->getMessage()));
                }
            }
        }

        return $sent;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeCustomFields(null|string $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function loadOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = (new Criteria([$orderId]))
            ->addAssociations([
                'orderCustomer',
                'orderCustomer.salutation',
                'deliveries.shippingMethod',
                'deliveries.shippingOrderAddress.country',
                'deliveries.stateMachineState',
            ]);

        /** @var OrderEntity|null $order */
    $order = $this->orderRepository->search($criteria, $context)->first();

    return $order;
    }
}
