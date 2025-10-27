<?php declare(strict_types=1);

namespace FoerdeClickCollect\EventSubscriber;

use FoerdeClickCollect\Service\PickupConfigResolver;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Content\Mail\Service\MailService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Content\MailTemplate\Aggregate\MailTemplateType\MailTemplateTypeEntity;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;

class OrderPlacedSubscriber implements EventSubscriberInterface
{
    private const STAFF_TEMPLATE_TYPE = 'fb_click_collect.staff_order_placed';
    private ?string $cachedStaffTemplateId = null;

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderDeliveryRepository,
        private readonly EntityRepository $mailTemplateTypeRepository,
        private readonly SystemConfigService $systemConfig,
        private readonly MailService $mailService,
        private readonly PickupConfigResolver $pickupConfigResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $context = $event->getContext();
        $order = $this->loadOrder($event->getOrderId(), $context);
        if (!$order) {
            return;
        }

        $delivery = $order->getDeliveries()?->first();
        $shipping = $delivery?->getShippingMethod();
        if (!$shipping || $shipping->getTechnicalName() !== 'click_collect') {
            return; // not a pickup order
        }

        $salesChannelId = $event->getSalesChannelId();
        $storeEmail = (string) ($this->systemConfig->get('FoerdeClickCollect.config.storeEmail', $salesChannelId) ?? '');
        if ($storeEmail === '') {
            return; // no recipient configured
        }

        $snapshot = $this->pickupConfigResolver->resolve($salesChannelId, $order->getLanguageId());
        $snapshotForStorage = [
            'storeName' => $snapshot['storeName'],
            'storeAddress' => $snapshot['storeAddress'],
            'openingHours' => $snapshot['openingHours'],
            'pickupWindowDays' => $snapshot['pickupWindowDays'],
            'pickupPreparationHours' => $snapshot['pickupPreparationHours'],
        ];
        $this->persistSnapshot($order, $snapshotForStorage, $context);

        $templateId = $this->resolveStaffTemplateId($context);
        if (!$templateId) {
            error_log('[FoerdeClickCollect] no staff mail template registered; skipping notification');
            return;
        }

        $storeName = $snapshotForStorage['storeName'] !== '' ? $snapshotForStorage['storeName'] : 'Store';
        $templateData = [
            'orderNumber' => $order->getOrderNumber(),
            'order' => $order,
            'config' => [
                'pickupWindowDays' => $snapshotForStorage['pickupWindowDays'],
                'pickupPreparationHours' => $snapshotForStorage['pickupPreparationHours'],
                'storeName' => $snapshotForStorage['storeName'],
                'storeAddress' => $snapshotForStorage['storeAddress'],
                'openingHours' => $snapshotForStorage['openingHours'],
            ],
        ];

        $senderName = trim((string) ($this->systemConfig->get('core.mailerSettings.senderName', $salesChannelId) ?? ''));
        if ($senderName === '') {
            $senderName = 'Click & Collect';
        }
        $senderEmail = trim((string) ($this->systemConfig->get('core.mailerSettings.senderAddress', $salesChannelId) ?? ''));

        $data = [
            'templateId' => $templateId,
            'recipients' => [$storeEmail => $storeName ?: 'Store'],
            'senderName' => $senderName,
            'salesChannelId' => $salesChannelId,
        ];
        if ($senderEmail !== '') {
            $data['senderEmail'] = $senderEmail;
        }

        try {
            $this->mailService->send($data, $context, $templateData);
        } catch (\Throwable $e) {
            error_log('[FoerdeClickCollect] staff mail send failed: ' . $e->getMessage());
        }
    }

    private function persistSnapshot(OrderEntity $order, array $snapshot, Context $context): void
    {
        $deliveries = $order->getDeliveries();
        if (!$deliveries) {
            return;
        }

        $updates = [];
        /** @var OrderDeliveryEntity $delivery */
        foreach ($deliveries as $delivery) {
            $shipping = $delivery->getShippingMethod();
            if (!$shipping || $shipping->getTechnicalName() !== 'click_collect') {
                continue;
            }

            $existing = $delivery->getCustomFields() ?? [];
            if (!$this->pickupConfigResolver->snapshotDiffers($existing, $snapshot)) {
                continue;
            }

            $updatedFields = $this->pickupConfigResolver->applySnapshotToCustomFields($existing, $snapshot);

            $updates[] = [
                'id' => $delivery->getId(),
                'versionId' => $delivery->getVersionId() ?? Defaults::LIVE_VERSION,
                'customFields' => $updatedFields,
            ];
        }

        if (!$updates) {
            return;
        }

        $this->orderDeliveryRepository->update($updates, $context);
    }

    private function loadOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = (new Criteria([$orderId]))
            ->addAssociation('deliveries.shippingMethod')
            ->addAssociation('orderCustomer')
            ->addAssociation('lineItems')
            ->addAssociation('addresses');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->first();
        return $order;
    }

    private function resolveStaffTemplateId(Context $context): ?string
    {
        if ($this->cachedStaffTemplateId !== null) {
            return $this->cachedStaffTemplateId;
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('technicalName', self::STAFF_TEMPLATE_TYPE))
            ->addAssociation('mailTemplates')
            ->setLimit(1);

        /** @var MailTemplateTypeEntity|null $type */
        $type = $this->mailTemplateTypeRepository->search($criteria, $context)->first();
        if (!$type) {
            return null;
        }

        $mailTemplates = $type->getMailTemplates();
        if (!$mailTemplates || $mailTemplates->count() === 0) {
            return null;
        }

        /** @var MailTemplateEntity|null $template */
        $template = $mailTemplates->first();

        $this->cachedStaffTemplateId = $template?->getId();

        return $this->cachedStaffTemplateId;
    }
}
