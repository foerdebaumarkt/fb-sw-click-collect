<?php declare(strict_types=1);

namespace FoerdeClickCollect;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Uuid\Uuid;

class FoerdeClickCollect extends Plugin
{
    private const TECHNICAL_NAME = 'click_collect';
    private const DISPLAY_NAME = 'Click & Collect';

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->provision($installContext->getContext());
    }

    public function postInstall(InstallContext $postInstallContext): void
    {
        parent::postInstall($postInstallContext);
        $this->provision($postInstallContext->getContext());
    }

    public function postUpdate(UpdateContext $postUpdateContext): void
    {
        parent::postUpdate($postUpdateContext);
        $this->provision($postUpdateContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        $this->provision($activateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        // Keep data to avoid breaking orders/history. No hard delete here.
    }

    public function getMigrationNamespace(): string
    {
        return 'FoerdeClickCollect\\Migration';
    }

    private function provision(Context $context): void
    {
        /** @var EntityRepository $shippingRepo */
        $shippingRepo = $this->container->get('shipping_method.repository');
        /** @var EntityRepository $deliveryTimeRepo */
        $deliveryTimeRepo = $this->container->get('delivery_time.repository');
        /** @var EntityRepository $salesChannelRepo */
        $salesChannelRepo = $this->container->get('sales_channel.repository');
        /** @var EntityRepository $paymentRepo */
        $paymentRepo = $this->container->get('payment_method.repository');
        /** @var EntityRepository $ruleRepo */
        $ruleRepo = $this->container->get('rule.repository');

        // Migrate from old technical name if present
        $shippingId = $this->getShippingMethodIdByTechnicalName($shippingRepo, $context, self::TECHNICAL_NAME)
            ?? $this->getShippingMethodIdByTechnicalName($shippingRepo, $context, 'foerde_click_collect')
            ?? Uuid::randomHex();
        $deliveryTimeId = $this->ensureDeliveryTime($deliveryTimeRepo, $context);

        $payload = [
            'id' => $shippingId,
            'technicalName' => self::TECHNICAL_NAME,
            'active' => true,
            'deliveryTimeId' => $deliveryTimeId,
            'taxType' => 'auto',
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => [
                    'name' => 'Abholung im Markt',
                    'description' => 'Die Ware steht in 4 Stunden für 2 Tage zur Abholung bereit.',
                ],
                'en-GB' => [
                    'name' => 'Pick up in store',
                    'description' => 'Available for pickup in 4 hours for 2 days.',
                ],
                'de-DE' => [
                    'name' => 'Abholung im Markt',
                    'description' => 'Die Ware steht in 4 Stunden für 2 Tage zur Abholung bereit.',
                ],
            ],
            'prices' => [
                [
                    'id' => Uuid::randomHex(),
                    'calculation' => 1,
                    'quantityStart' => 1,
                    'currencyPrice' => [
                        [
                            'currencyId' => Defaults::CURRENCY,
                            'gross' => 0.0,
                            'net' => 0.0,
                            'linked' => false,
                        ],
                    ],
                ],
            ],
        ];

        try {
            $shippingRepo->upsert([$payload], $context);
        } catch (\Throwable $e) {
            // Surface errors in the PHP error log for quick debugging
            error_log('[FoerdeClickCollect] Upsert shipping method failed: ' . $e->getMessage());
            throw $e;
        }

        // Attach to all Storefront sales channels (non-destructive merge)
        try {
            $criteria = (new Criteria())->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));
            $salesChannels = $salesChannelRepo->search($criteria, $context)->getEntities();
            $updates = [];
            foreach ($salesChannels as $sc) {
                $updates[] = [
                    'id' => $sc->getId(),
                    'shippingMethods' => [
                        ['id' => $shippingId],
                    ],
                ];
            }
            if ($updates) {
                $salesChannelRepo->upsert($updates, $context);
            }
        } catch (\Throwable $e) {
            error_log('[FoerdeClickCollect] Linking to sales channels failed: ' . $e->getMessage());
            // Non-fatal: shipping method exists regardless
        }

        // Provision payment method: technical_name 'click_collect'
        $paymentId = $this->getPaymentMethodIdByTechnicalName($paymentRepo, $context, self::TECHNICAL_NAME)
            ?? $this->getPaymentMethodIdByTechnicalName($paymentRepo, $context, 'foerde_click_collect')
            ?? Uuid::randomHex();

        // Ensure availability rule limited to the pickup shipping method
        $ruleCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('name', 'Click & Collect: only with pickup shipping'))
            ->setLimit(1);
        $existingRule = $ruleRepo->search($ruleCriteria, $context)->first();
        $ruleId = $existingRule ? $existingRule->getId() : Uuid::randomHex();

        $rulePayload = [
            'id' => $ruleId,
            'name' => 'Click & Collect: only with pickup shipping',
            'priority' => 1,
            'conditions' => [
                [
                    'id' => Uuid::randomHex(),
                    'type' => 'shippingMethod',
                    'value' => [
                        // Use Shopware Rule::OPERATOR_EQ ('=') for UUID comparisons
                        'operator' => '=',
                        'shippingMethodIds' => [$shippingId],
                    ],
                ],
            ],
        ];

        try {
            $ruleRepo->upsert([$rulePayload], $context);
        } catch (\Throwable $e) {
            error_log('[FoerdeClickCollect] Upsert availability rule failed: ' . $e->getMessage());
            throw $e;
        }

        $handlerClass = \FoerdeClickCollect\Service\Payment\ClickCollectPaymentHandler::class;
        $paymentPayload = [
            'id' => $paymentId,
            'technicalName' => self::TECHNICAL_NAME,
            'handlerIdentifier' => $handlerClass,
            'active' => true,
            'availabilityRuleId' => $ruleId,
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => [
                    'name' => 'Bezahlung im Markt',
                    'description' => 'Bezahlung bei Abholung im Markt (kein Online-Bezahlschritt).',
                ],
                'de-DE' => [
                    'name' => 'Bezahlung im Markt',
                    'description' => 'Bezahlung bei Abholung im Markt (kein Online-Bezahlschritt).',
                ],
                'en-GB' => [
                    'name' => 'Pay in store',
                    'description' => 'Payment is completed in-store at pickup (no online step).',
                ],
            ],
        ];

        try {
            $paymentRepo->upsert([$paymentPayload], $context);
        } catch (\Throwable $e) {
            error_log('[FoerdeClickCollect] Upsert payment method failed: ' . $e->getMessage());
            throw $e;
        }

        // Attach payment method to all Storefront sales channels
        try {
            $criteria = (new Criteria())->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));
            $salesChannels = $salesChannelRepo->search($criteria, $context)->getEntities();
            $updates = [];
            foreach ($salesChannels as $sc) {
                $updates[] = [
                    'id' => $sc->getId(),
                    'paymentMethods' => [
                        ['id' => $paymentId],
                    ],
                ];
            }
            if ($updates) {
                $salesChannelRepo->upsert($updates, $context);
            }
        } catch (\Throwable $e) {
            error_log('[FoerdeClickCollect] Linking payment to sales channels failed: ' . $e->getMessage());
        }
    }

    private function ensureDeliveryTime(EntityRepository $deliveryTimeRepo, Context $context): string
    {
        // Try to reuse an existing delivery time
        $existing = $deliveryTimeRepo->search((new Criteria())->setLimit(1), $context)->first();
        if ($existing) {
            return $existing->getId();
        }

        $id = Uuid::randomHex();
        $payload = [
            'id' => $id,
            'min' => 1,
            'max' => 3,
            'unit' => 'day',
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => [
                    'name' => '1-3 days',
                ],
                'en-GB' => [
                    'name' => '1-3 days',
                ],
                'de-DE' => [
                    'name' => '1-3 Tage',
                ],
            ],
        ];

        $deliveryTimeRepo->upsert([$payload], $context);
        return $id;
    }

    private function getShippingMethodIdByTechnicalName(EntityRepository $shippingRepo, Context $context, string $technicalName): ?string
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('technicalName', $technicalName))->setLimit(1);
        $entity = $shippingRepo->search($criteria, $context)->first();
        return $entity ? $entity->getId() : null;
    }

    private function getPaymentMethodIdByTechnicalName(EntityRepository $paymentRepo, Context $context, string $technicalName): ?string
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('technicalName', $technicalName))->setLimit(1);
        $entity = $paymentRepo->search($criteria, $context)->first();
        return $entity ? $entity->getId() : null;
    }
}
