<?php declare(strict_types=1);

namespace FoerdeClickCollect\EventSubscriber;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\Event\OrderPlacedEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Content\Mail\Service\MailService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Twig\Environment as TwigEnvironment;

class OrderPlacedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly SystemConfigService $systemConfig,
        private readonly MailService $mailService,
        private readonly TwigEnvironment $twig
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onOrderPlaced(OrderPlacedEvent $event): void
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

        $pickupDays = (int) ($this->systemConfig->get('FoerdeClickCollect.config.pickupWindowDays', $salesChannelId) ?? 2);
        $prepHours = (int) ($this->systemConfig->get('FoerdeClickCollect.config.pickupPreparationHours', $salesChannelId) ?? 4);
        $storeName = (string) ($this->systemConfig->get('FoerdeClickCollect.config.storeName', $salesChannelId) ?? '');
        $storeAddress = (string) ($this->systemConfig->get('FoerdeClickCollect.config.storeAddress', $salesChannelId) ?? '');
        $openingHours = (string) ($this->systemConfig->get('FoerdeClickCollect.config.storeOpeningHours', $salesChannelId) ?? '');

        $html = $this->twig->render('@FoerdeClickCollect/email/click_collect_staff_order_placed.html.twig', [
            'order' => $order,
            'pickupDays' => $pickupDays,
            'prepHours' => $prepHours,
            'storeName' => $storeName,
            'storeAddress' => $storeAddress,
            'openingHours' => $openingHours,
        ]);

        $subject = sprintf('Neue Click & Collect Bestellung #%s', $order->getOrderNumber());

        $data = [
            'recipients' => [ $storeEmail => $storeName ?: 'Store' ],
            'senderName' => 'Click & Collect',
            'contentHtml' => $html,
            'subject' => $subject,
            'salesChannelId' => $salesChannelId,
        ];

        try {
            $this->mailService->send($data, $context, $salesChannelId);
        } catch (\Throwable $e) {
            error_log('[FoerdeClickCollect] staff mail send failed: ' . $e->getMessage());
        }
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
}
