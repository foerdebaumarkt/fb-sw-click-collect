<?php declare(strict_types=1);

namespace FbClickCollect;

use FbClickCollect\Event\PickupReminderEvent;
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

class FbClickCollect extends Plugin
{
    private const TECHNICAL_NAME = 'click_collect';
    private const DISPLAY_NAME = 'Click & Collect';
    private const STAFF_TEMPLATE_TYPE = 'fb_click_collect.staff_order_placed';

    private const STAFF_TEMPLATE_SUBJECT_DE = 'Neue Click & Collect Bestellung #{{ orderNumber }}';
    private const STAFF_TEMPLATE_SUBJECT_EN = 'New Click & Collect order #{{ orderNumber }}';

    private const STAFF_TEMPLATE_HTML_DE = <<<'HTML'
<p>Hallo Team,</p>
<p>es liegt eine neue Click & Collect Bestellung <strong>#{{ orderNumber }}</strong> vor, die als Abholung im Markt markiert ist.</p>
<h3>Kundendaten</h3>
{% set oc = order.orderCustomer %}
<p>{% if oc %}{{ oc.firstName }} {{ oc.lastName }}<br/>{{ oc.email }}{% else %}–{% endif %}</p>
<h3>Bestellpositionen</h3>
<table width="100%" cellpadding="4" cellspacing="0" border="1" style="border-collapse:collapse;">
  <thead>
    <tr>
      <th align="left">Artikel</th>
      <th align="right">Menge</th>
      <th align="right">Preis</th>
    </tr>
  </thead>
  <tbody>
    {% for item in order.lineItems %}
      <tr>
        <td>{{ item.label }}</td>
        <td align="right">{{ item.quantity }}</td>
        <td align="right">{{ item.price.unitPrice|number_format(2, ',', '.') }}&nbsp;€</td>
      </tr>
    {% endfor %}
  </tbody>
  <tfoot>
    <tr>
      <td colspan="2" align="right"><strong>Gesamtsumme</strong></td>
      <td align="right"><strong>{{ order.amountTotal|number_format(2, ',', '.') }}&nbsp;€</strong></td>
    </tr>
  </tfoot>
</table>
<h3>Abholhinweise</h3>
<p>Bitte bereitet die Bestellung innerhalb von <strong>{{ config.pickupPreparationHours }}</strong> Stunden vor. Die Abholung ist für <strong>{{ config.pickupWindowDays }}</strong> Tage möglich.</p>
<h3>Markt</h3>
<p>{% if config.storeName %}<strong>{{ config.storeName }}</strong><br/>{% endif %}
{% if config.storeAddress %}{{ config.storeAddress|nl2br }}<br/>{% endif %}
{% if config.openingHours %}<em>Öffnungszeiten:</em><br/>{{ config.openingHours|nl2br }}{% endif %}</p>
<hr/>
<p style="color:#666; font-size:12px;">Diese E-Mail wurde automatisch vom Click & Collect Plugin generiert.</p>
HTML;
    private const STAFF_TEMPLATE_HTML_EN = <<<'HTML'
<p>Hello team,</p>
<p>A new Click & Collect order <strong>#{{ orderNumber }}</strong> has been placed and marked for in-store pickup.</p>
<h3>Customer</h3>
{% set oc = order.orderCustomer %}
<p>{% if oc %}{{ oc.firstName }} {{ oc.lastName }}<br/>{{ oc.email }}{% else %}–{% endif %}</p>
<h3>Items</h3>
<table width="100%" cellpadding="4" cellspacing="0" border="1" style="border-collapse:collapse;">
  <thead>
    <tr>
      <th align="left">Item</th>
      <th align="right">Qty</th>
      <th align="right">Price</th>
    </tr>
  </thead>
  <tbody>
    {% for item in order.lineItems %}
      <tr>
        <td>{{ item.label }}</td>
        <td align="right">{{ item.quantity }}</td>
        <td align="right">{{ item.price.unitPrice|number_format(2, '.', ',') }}&nbsp;€</td>
      </tr>
    {% endfor %}
  </tbody>
  <tfoot>
    <tr>
      <td colspan="2" align="right"><strong>Total</strong></td>
      <td align="right"><strong>{{ order.amountTotal|number_format(2, '.', ',') }}&nbsp;€</strong></td>
    </tr>
  </tfoot>
</table>
<h3>Pickup details</h3>
<p>Please prepare the order within <strong>{{ config.pickupPreparationHours }}</strong> hours. Pickup is available for <strong>{{ config.pickupWindowDays }}</strong> days.</p>
<h3>Store</h3>
<p>{% if config.storeName %}<strong>{{ config.storeName }}</strong><br/>{% endif %}
{% if config.storeAddress %}{{ config.storeAddress|nl2br }}<br/>{% endif %}
{% if config.openingHours %}<em>Opening hours:</em><br/>{{ config.openingHours|nl2br }}{% endif %}</p>
<hr/>
<p style="color:#666; font-size:12px;">This email was generated automatically by the Click & Collect plugin.</p>
HTML;

    private const STAFF_TEMPLATE_PLAIN_DE = <<<'TEXT'
Hallo Team,

es liegt eine neue Click & Collect Bestellung #{{ orderNumber }} vor, die als Abholung im Markt markiert ist.

Kundendaten:
{% set oc = order.orderCustomer %}{% if oc %}{{ oc.firstName }} {{ oc.lastName }} / {{ oc.email }}{% else %}–{% endif %}

Bestellpositionen:
{% for item in order.lineItems %}- {{ item.label }} ({{ item.quantity }} × {{ item.price.unitPrice|number_format(2, ',', '.') }} €)
{% endfor %}
Gesamtsumme: {{ order.amountTotal|number_format(2, ',', '.') }} €

Abholhinweise:
- Vorbereitung: {{ config.pickupPreparationHours }} Stunden
- Abholung möglich für: {{ config.pickupWindowDays }} Tage

Markt:
{% if config.storeName %}{{ config.storeName }}
{% endif %}{% if config.storeAddress %}{{ config.storeAddress }}
{% endif %}{% if config.openingHours %}Öffnungszeiten:
{{ config.openingHours }}
{% endif %}

Diese E-Mail wurde automatisch vom Click & Collect Plugin generiert.
TEXT;

    private const STAFF_TEMPLATE_PLAIN_EN = <<<'TEXT'
Hello team,

A new Click & Collect order #{{ orderNumber }} has been placed and marked for in-store pickup.

Customer:
{% set oc = order.orderCustomer %}{% if oc %}{{ oc.firstName }} {{ oc.lastName }} / {{ oc.email }}{% else %}–{% endif %}

Items:
{% for item in order.lineItems %}- {{ item.label }} ({{ item.quantity }} × {{ item.price.unitPrice|number_format(2, '.', ',') }} €)
{% endfor %}
Total: {{ order.amountTotal|number_format(2, '.', ',') }} €

Pickup details:
- Preparation: {{ config.pickupPreparationHours }} hours
- Pickup window: {{ config.pickupWindowDays }} days

Store:
{% if config.storeName %}{{ config.storeName }}
{% endif %}{% if config.storeAddress %}{{ config.storeAddress }}
{% endif %}{% if config.openingHours %}Opening hours:
{{ config.openingHours }}
{% endif %}

This email was generated automatically by the Click & Collect plugin.
TEXT;

    protected function getActionEventClasses(): array
    {
        return array_merge(parent::getActionEventClasses(), [
            PickupReminderEvent::class,
        ]);
    }

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
        return 'FbClickCollect\\Migration';
    }

    private function provision(Context $context): void
    {
        // Align the reminders scheduled task next run to configured time after install/update/activate
        $this->alignRemindersTaskNextRun();
        $this->ensureStaffMailTemplate($context);
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
            ?? $this->getShippingMethodIdByTechnicalName($shippingRepo, $context, 'fb_click_collect')
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
            error_log('[FbClickCollect] Upsert shipping method failed: ' . $e->getMessage());
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
            error_log('[FbClickCollect] Linking to sales channels failed: ' . $e->getMessage());
            // Non-fatal: shipping method exists regardless
        }

        // Provision payment method: technical_name 'click_collect'
        $paymentId = $this->getPaymentMethodIdByTechnicalName($paymentRepo, $context, self::TECHNICAL_NAME)
            ?? $this->getPaymentMethodIdByTechnicalName($paymentRepo, $context, 'fb_click_collect')
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
            error_log('[FbClickCollect] Upsert availability rule failed: ' . $e->getMessage());
            throw $e;
        }

        $handlerClass = \FbClickCollect\Service\Payment\ClickCollectPaymentHandler::class;
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
            error_log('[FbClickCollect] Upsert payment method failed: ' . $e->getMessage());
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
            error_log('[FbClickCollect] Linking payment to sales channels failed: ' . $e->getMessage());
        }
    }

    private function ensureStaffMailTemplate(Context $context): void
    {
        /** @var EntityRepository $typeRepo */
        $typeRepo = $this->container->get('mail_template_type.repository');
        /** @var EntityRepository $templateRepo */
        $templateRepo = $this->container->get('mail_template.repository');

        $typeCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('technicalName', self::STAFF_TEMPLATE_TYPE))
            ->setLimit(1);
        $existingType = $typeRepo->search($typeCriteria, $context)->first();
        $typeId = $existingType?->getId() ?? Uuid::randomHex();

        $typeRepo->upsert([
            [
                'id' => $typeId,
                'technicalName' => self::STAFF_TEMPLATE_TYPE,
                'availableEntities' => [
                    'order' => 'order',
                    'order_delivery' => 'order_delivery',
                    'sales_channel' => 'sales_channel',
                    'customer' => 'customer',
                ],
                'name' => 'Click & Collect: Staff notification',
                'translations' => [
                    Defaults::LANGUAGE_SYSTEM => [
                        'name' => 'Click & Collect: Staff notification',
                    ],
                    'en-GB' => [
                        'name' => 'Click & Collect: Staff notification',
                    ],
                    'de-DE' => [
                        'name' => 'Click & Collect: Team-Benachrichtigung',
                    ],
                ],
            ],
        ], $context);

        $templateCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('mailTemplateTypeId', $typeId))
            ->setLimit(1);
        $existingTemplate = $templateRepo->search($templateCriteria, $context)->first();
        $templateId = $existingTemplate?->getId() ?? Uuid::randomHex();

        $templateRepo->upsert([
            [
                'id' => $templateId,
                'mailTemplateTypeId' => $typeId,
                'systemDefault' => true,
                'subject' => self::STAFF_TEMPLATE_SUBJECT_EN,
                'contentHtml' => self::STAFF_TEMPLATE_HTML_EN,
                'contentPlain' => self::STAFF_TEMPLATE_PLAIN_EN,
                'translations' => [
                    Defaults::LANGUAGE_SYSTEM => [
                        'subject' => self::STAFF_TEMPLATE_SUBJECT_EN,
                        'contentHtml' => self::STAFF_TEMPLATE_HTML_EN,
                        'contentPlain' => self::STAFF_TEMPLATE_PLAIN_EN,
                    ],
                    'en-GB' => [
                        'subject' => self::STAFF_TEMPLATE_SUBJECT_EN,
                        'contentHtml' => self::STAFF_TEMPLATE_HTML_EN,
                        'contentPlain' => self::STAFF_TEMPLATE_PLAIN_EN,
                    ],
                    'de-DE' => [
                        'subject' => self::STAFF_TEMPLATE_SUBJECT_DE,
                        'contentHtml' => self::STAFF_TEMPLATE_HTML_DE,
                        'contentPlain' => self::STAFF_TEMPLATE_PLAIN_DE,
                    ],
                ],
            ],
        ], $context);
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

    private function alignRemindersTaskNextRun(): void
    {
        try {
            /** @var EntityRepository $taskRepo */
            $taskRepo = $this->container->get('scheduled_task.repository');
            /** @var \Shopware\Core\System\SystemConfig\SystemConfigService $systemConfig */
            $systemConfig = $this->container->get(\Shopware\Core\System\SystemConfig\SystemConfigService::class);

            $criteria = (new Criteria())
                ->addFilter(new EqualsFilter('name', \FbClickCollect\ScheduledTask\SendRemindersTask::getTaskName()))
                ->setLimit(1);
            $ctx = Context::createDefaultContext();
            $task = $taskRepo->search($criteria, $ctx)->first();
            if (!$task) {
                return;
            }

            $timeStr = (string) ($systemConfig->get('FbClickCollect.config.reminderRunTime') ?? '06:00');
            if (!preg_match('/^(\d{1,2}):(\d{2})$/', $timeStr, $m)) {
                $m = [null, '06', '00'];
            }
            $hour = min(23, max(0, (int) $m[1]));
            $minute = min(59, max(0, (int) $m[2]));

            $tzId = (string) ($systemConfig->get('core.basicInformation.timezone') ?? 'Europe/Berlin');
            try {
                $tz = new \DateTimeZone($tzId);
            } catch (\Throwable) {
                $tz = new \DateTimeZone('Europe/Berlin');
            }

            $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $nowLocal = $nowUtc->setTimezone($tz);
            $targetLocal = $nowLocal->setTime($hour, $minute, 0);
            if ($targetLocal <= $nowLocal) {
                $targetLocal = $targetLocal->modify('+1 day');
            }
            $targetUtc = $targetLocal->setTimezone(new \DateTimeZone('UTC'));

            $taskRepo->update([[
                'id' => $task->getUniqueIdentifier(),
                'runInterval' => 86400,
                'nextExecutionTime' => $targetUtc,
            ]], $ctx);
        } catch (\Throwable $e) {
            // non-fatal: scheduling will still run daily, just not aligned to time-of-day until first execution
            error_log('[FbClickCollect] Failed to align reminders task next run: ' . $e->getMessage());
        }
    }
}
