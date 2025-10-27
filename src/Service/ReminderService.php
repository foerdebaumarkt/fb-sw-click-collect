<?php declare(strict_types=1);

namespace FoerdeClickCollect\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Mail\Service\MailService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ReminderService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MailService $mailService,
        private readonly SystemConfigService $systemConfig,
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
            $salesChannelId = $row['sales_channel_id'];
            $languageId = $row['language_id'] ?? null;
            $orderCreated = $row['order_created'] ? new \DateTimeImmutable((string) $row['order_created']) : null;
            $email = (string) $row['email'];
            $customerName = (string) ($row['customer_name'] ?: $email);
            $orderNumber = (string) $row['order_number'];

            if (!$orderCreated) {
                continue;
            }

            $salesChannelIdHex = is_string($salesChannelId) ? Uuid::fromBytesToHex($salesChannelId) : null;
            $languageIdHex = is_string($languageId) ? Uuid::fromBytesToHex($languageId) : null;

            $deliveryCustomFields = $this->decodeCustomFields($row['delivery_custom_fields'] ?? null);
            $snapshot = $this->pickupConfigResolver->extractSnapshotFromCustomFields($deliveryCustomFields)
                ?? $this->pickupConfigResolver->resolve($salesChannelIdHex ?? $salesChannelId, $languageId);

            $pickupWindowDays = $snapshot['pickupWindowDays'];
            $storeName = $snapshot['storeName'] !== '' ? $snapshot['storeName'] : 'Ihr Markt';
            $storeAddress = $snapshot['storeAddress'];
            $openingHoursCfg = $snapshot['openingHours'];

            $expiry = $orderCreated->modify('+' . $pickupWindowDays . ' days');
            if ($expiry < $now) {
                continue; // outside pickup window
            }

            // Resolve translation for template
            $templateTrans = null;
            if (is_string($languageId)) {
                $templateTrans = $this->connection->fetchAssociative(
                    'SELECT subject, content_html, content_plain FROM mail_template_translation WHERE mail_template_id = :tid AND language_id = :lid',
                    ['tid' => $templateId, 'lid' => $languageId]
                );
            }
            if (!$templateTrans) {
                $defaultLang = hex2bin('2fbb5fe2e29a4d70aa5854ce7ce3e20b');
                $templateTrans = $this->connection->fetchAssociative(
                    'SELECT subject, content_html, content_plain FROM mail_template_translation WHERE mail_template_id = :tid AND language_id = :lid',
                    ['tid' => $templateId, 'lid' => $defaultLang]
                );
            }
            if (!$templateTrans) {
                $templateTrans = $this->connection->fetchAssociative(
                    'SELECT subject, content_html, content_plain FROM mail_template_translation WHERE mail_template_id = :tid ORDER BY created_at DESC LIMIT 1',
                    ['tid' => $templateId]
                );
            }
            if (!$templateTrans) {
                throw new \RuntimeException('No translation found for reminder template.');
            }

            $subject = (string) ($templateTrans['subject'] ?? '');
            $contentHtml = (string) ($templateTrans['content_html'] ?? '');
            $contentPlain = (string) ($templateTrans['content_plain'] ?? '');
            if ($subject === '' && $contentHtml === '' && $contentPlain === '') {
                throw new \RuntimeException('Reminder template translation has no subject or content.');
            }

            $data = [
                'recipients' => [ $email => $customerName ],
                ...(isset($salesChannelIdHex) ? ['salesChannelId' => $salesChannelIdHex] : []),
                ...(isset($languageIdHex) ? ['languageId' => $languageIdHex] : []),
                'senderName' => $this->resolveSenderName($salesChannelIdHex, $snapshot['storeName']),
                'senderEmail' => $this->resolveSenderEmail($salesChannelIdHex),
                'contentHtml' => $contentHtml,
                'contentPlain' => $contentPlain,
                'subject' => $subject,
            ];
            $templateData = [
                'orderNumber' => $orderNumber,
                'config' => [
                    'storeName' => $snapshot['storeName'],
                    'storeAddress' => $storeAddress,
                    'openingHours' => $openingHoursCfg,
                    'pickupWindowDays' => $pickupWindowDays,
                ],
            ];

            try {
                $this->mailService->send($data, Context::createDefaultContext(), $templateData);
                // Mark order as reminded to avoid duplicates
                $orderId = $row['order_id'];
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

    private function resolveSenderName(?string $salesChannelId, string $storeName): string
    {
        $configured = trim((string) ($this->systemConfig->get('core.mailerSettings.senderName', $salesChannelId) ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        return $storeName !== '' ? $storeName : 'Click & Collect';
    }

    private function resolveSenderEmail(?string $salesChannelId): string
    {
        $configured = trim((string) ($this->systemConfig->get('core.mailerSettings.senderAddress', $salesChannelId) ?? ''));

        return $configured !== '' ? $configured : 'no-reply@example.com';
    }
}
