<?php declare(strict_types=1);

namespace FoerdeClickCollect\Event;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\Aware\ScalarValuesAware;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\CustomerAware;
use Shopware\Core\Framework\Event\CustomerDeletedException;
use Shopware\Core\Framework\Event\EventData\ArrayType;
use Shopware\Core\Framework\Event\EventData\EntityType;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\LanguageAware;
use Shopware\Core\Framework\Event\MailAware;
use Shopware\Core\Framework\Event\OrderAware;
use Shopware\Core\Framework\Event\SalesChannelAware;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;
use Shopware\Core\Framework\Log\Package;
use Symfony\Contracts\EventDispatcher\Event;

#[Package('services-settings')]
class PickupReminderEvent extends Event implements FlowEventAware, OrderAware, SalesChannelAware, MailAware, CustomerAware, LanguageAware, ScalarValuesAware
{
    public const EVENT_NAME = 'foerde.click_collect.pickup_reminder';

    /** @var array{storeName:string,storeAddress:string,openingHours:string,pickupWindowDays:int,pickupPreparationHours:int} */
    private array $snapshot;

    private ?MailRecipientStruct $mailStruct = null;

    public function __construct(
        private readonly Context $context,
        private readonly OrderEntity $order,
        private readonly string $salesChannelId,
        array $snapshot,
        private readonly ?string $languageId
    ) {
        $this->snapshot = [
            'storeName' => $snapshot['storeName'] ?? '',
            'storeAddress' => $snapshot['storeAddress'] ?? '',
            'openingHours' => $snapshot['openingHours'] ?? '',
            'pickupWindowDays' => (int) ($snapshot['pickupWindowDays'] ?? 0),
            'pickupPreparationHours' => (int) ($snapshot['pickupPreparationHours'] ?? 0),
        ];
    }

    public static function getAvailableData(): EventDataCollection
    {
        return (new EventDataCollection())
            ->add('order', new EntityType(OrderDefinition::class))
            ->add('config', new ArrayType(new ScalarValueType(ScalarValueType::TYPE_STRING)))
            ->add('clickCollectPickup', new ArrayType(new ScalarValueType(ScalarValueType::TYPE_STRING)))
            ->add('orderNumber', new ScalarValueType(ScalarValueType::TYPE_STRING));
    }

    public function getName(): string
    {
        return self::EVENT_NAME;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    public function getOrderId(): string
    {
        return $this->order->getId();
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function getCustomerId(): string
    {
        $customer = $this->order->getOrderCustomer();

        if ($customer === null || $customer->getCustomerId() === null) {
            throw new CustomerDeletedException($this->getOrderId());
        }

        return $customer->getCustomerId();
    }

    public function getLanguageId(): ?string
    {
        return $this->languageId;
    }

    public function getMailStruct(): MailRecipientStruct
    {
        if ($this->mailStruct === null) {
            $orderCustomer = $this->order->getOrderCustomer();
            $email = trim((string) ($orderCustomer?->getEmail() ?? ''));
            if ($email === '') {
                $email = 'no-reply@example.com';
            }

            $name = trim(sprintf(
                '%s %s',
                (string) ($orderCustomer?->getFirstName() ?? ''),
                (string) ($orderCustomer?->getLastName() ?? '')
            ));
            if ($name === '') {
                $name = $email;
            }

            $this->mailStruct = new MailRecipientStruct([
                $email => $name,
            ]);
        }

        return $this->mailStruct;
    }

    /**
     * @return array<string, scalar|array<mixed>|null>
     */
    public function getValues(): array
    {
        return [
            'config' => $this->snapshot,
            'clickCollectPickup' => $this->snapshot,
            'orderNumber' => $this->order->getOrderNumber(),
        ];
    }
}
