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
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderPlacedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderDeliveryRepository,
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
        $snapshot = $this->pickupConfigResolver->resolve($salesChannelId, $order->getLanguageId());
        $snapshotForStorage = [
            'storeName' => $snapshot['storeName'],
            'storeAddress' => $snapshot['storeAddress'],
            'openingHours' => $snapshot['openingHours'],
            'storeEmail' => $snapshot['storeEmail'],
            'pickupWindowDays' => $snapshot['pickupWindowDays'],
            'pickupPreparationHours' => $snapshot['pickupPreparationHours'],
        ];
        $this->persistSnapshot($order, $snapshotForStorage, $context);
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
}
