<?php declare(strict_types=1);

namespace FbClickCollect\EventSubscriber;

use FbClickCollect\Service\PickupConfigResolver;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\Action\FlowMailVariables;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeSentEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injects the persisted Click & Collect snapshot into order confirmation mails.
 */
class OrderConfirmationMailSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly PickupConfigResolver $pickupConfigResolver)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MailBeforeSentEvent::EVENT_NAME => 'enrichOrderConfirmation',
        ];
    }

    public function enrichOrderConfirmation(MailBeforeSentEvent $event): void
    {
        $data = $event->getData();
        $mailEventName = $data['eventName'] ?? null;

        if (!is_string($mailEventName) || $mailEventName !== 'checkout.order.placed') {
            return;
        }

        $mailData = $data[FlowMailVariables::DATA] ?? null;
        if (!is_array($mailData)) {
            return;
        }

        $order = $mailData['order'] ?? null;
        if (!$order instanceof OrderEntity) {
            return;
        }

        $delivery = $order->getDeliveries()?->first();
        if (!$delivery instanceof OrderDeliveryEntity) {
            return;
        }

        $shipping = $delivery->getShippingMethod();
        if (!$shipping || $shipping->getTechnicalName() !== 'click_collect') {
            return;
        }

        $snapshot = $this->pickupConfigResolver->extractSnapshotFromCustomFields($delivery->getCustomFields() ?? [])
            ?? $this->pickupConfigResolver->resolve($order->getSalesChannelId() ?? '', $order->getLanguageId());

        $mailData['clickCollectPickup'] = [
            'storeName' => $snapshot['storeName'],
            'storeAddress' => $snapshot['storeAddress'],
            'openingHours' => $snapshot['openingHours'],
            'pickupWindowDays' => $snapshot['pickupWindowDays'],
            'pickupPreparationHours' => $snapshot['pickupPreparationHours'],
        ];
        $data[FlowMailVariables::DATA] = $mailData;
        $this->replaceEventData($event, $data);
    }

    private function replaceEventData(MailBeforeSentEvent $event, array $newData): void
    {
        if (method_exists($event, 'setData')) {
            $event->setData($newData);
            return;
        }

        $ref = new \ReflectionObject($event);
        if ($ref->hasProperty('data')) {
            $property = $ref->getProperty('data');
            $property->setAccessible(true);
            $property->setValue($event, $newData);
        }
    }
}
