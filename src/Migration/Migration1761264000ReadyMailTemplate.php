<?php declare(strict_types=1);

namespace FbClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1761264000ReadyMailTemplate extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        // 2025-10-24 00:00:00 UTC
        return 1761264000;
    }

    public function update(Connection $connection): void
    {
        // Fresh install: ensure fb_* type exists (no legacy renames)
        $typeId = $connection->fetchOne('SELECT id FROM mail_template_type WHERE technical_name = :name', [
            'name' => 'fb_click_collect.ready',
        ]);

        if (!$typeId) {
            $typeId = Uuid::randomBytes();
            $connection->insert('mail_template_type', [
                'id' => $typeId,
                'technical_name' => 'fb_click_collect.ready',
                'available_entities' => json_encode(['order' => 'order'], JSON_THROW_ON_ERROR),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            $this->insertTypeTranslation($connection, $typeId, 'de-DE', 'Click & Collect: Abholbereit');
            $this->insertTypeTranslation($connection, $typeId, 'en-GB', 'Click & Collect: Ready for pickup');
        }

        // Ensure there's a template for the type
        $templateId = $connection->fetchOne('SELECT id FROM mail_template WHERE mail_template_type_id = :tid', [
            'tid' => $typeId,
        ]);
        if (!$templateId) {
            $templateId = Uuid::randomBytes();
            $connection->insert('mail_template', [
                'id' => $templateId,
                'mail_template_type_id' => $typeId,
                'system_default' => 0,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } else {
            if (is_string($templateId) && preg_match('/^[0-9a-f]{32}$/i', $templateId)) {
                $templateId = Uuid::fromHexToBytes($templateId);
            }
        }

        // Upsert DE/EN translations
    $this->upsertTemplateTranslation($connection, $templateId, 'de-DE',
        'Ihre Bestellung #{{ orderNumber|default(order is defined ? order.orderNumber|default(\'\') : \'\') }} ist abholbereit',
        <<<HTML
{% set orderEntity = order is defined ? order : null %}
{% set orderNumberResolved = orderNumber|default(orderEntity ? orderEntity.orderNumber|default('') : '') %}
{% set customer = customer|default(orderEntity ? orderEntity.orderCustomer|default({}) : {}) %}
{% set pickupDelivery = orderEntity ? orderEntity.deliveries|first : null %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set fallbackPickup = (pickup is defined and pickup is iterable) ? pickup : config|default({}) %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(fallbackPickup.storeName|default('')),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(fallbackPickup.storeAddress|default('')),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(fallbackPickup.openingHours|default('')),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(fallbackPickup.pickupWindowDays|default(2)),
    'pickupPreparationHours': pickupFields.fb_click_collect_pickup_preparation_hours|default(fallbackPickup.pickupPreparationHours|default(4))
} %}
<p>Hallo {{ customer.firstName|default('') }} {{ customer.lastName|default('') }},</p>
<p>Ihre Click & Collect Bestellung <strong>#{{ orderNumberResolved }}</strong> ist abholbereit und liegt für Sie im Markt bereit.</p>
<p><strong>Abholung</strong><br/>{{ pickup.storeName|default('Ihr Markt') }}{% if pickup.storeAddress|default('') %}<br/>{{ pickup.storeAddress|nl2br }}{% endif %}{% if pickup.openingHours|default('') %}<br/><em>Öffnungszeiten:</em><br/>{{ pickup.openingHours|nl2br }}{% endif %}</p>
<p><strong>Bitte mitbringen</strong></p>
<ul>
    <li>Bestellnummer <strong>#{{ orderNumberResolved }}</strong></li>
    <li>Diese E-Mail (optional)</li>
    <li>Zur Abholung genügt es, Ihren Namen zu nennen; die Bezahlung erfolgt im Markt.</li>
    <li>Bitte holen Sie die Ware innerhalb von <strong>{{ pickup.pickupWindowDays|default(2) }}</strong> Tagen ab.</li>
</ul>
<p>Vielen Dank und bis bald!<br/>Ihr Förde Baumarkt Team</p>
HTML,
        <<<PLAIN
{% set orderEntity = order is defined ? order : null %}
{% set orderNumberResolved = orderNumber|default(orderEntity ? orderEntity.orderNumber|default('') : '') %}
{% set customer = customer|default(orderEntity ? orderEntity.orderCustomer|default({}) : {}) %}
{% set pickupDelivery = orderEntity ? orderEntity.deliveries|first : null %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set fallbackPickup = (pickup is defined and pickup is iterable) ? pickup : config|default({}) %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(fallbackPickup.storeName|default('')),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(fallbackPickup.storeAddress|default('')),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(fallbackPickup.openingHours|default('')),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(fallbackPickup.pickupWindowDays|default(2)),
    'pickupPreparationHours': pickupFields.fb_click_collect_pickup_preparation_hours|default(fallbackPickup.pickupPreparationHours|default(4))
} %}
Hallo {{ customer.firstName|default('') }} {{ customer.lastName|default('') }}

Ihre Click & Collect Bestellung #{{ orderNumberResolved }} ist abholbereit und liegt für Sie im Markt bereit.

Abholung:
{{ pickup.storeName|default('Ihr Markt') }}
{% if pickup.storeAddress|default('') %}{{ pickup.storeAddress }}
{% endif %}{% if pickup.openingHours|default('') %}Öffnungszeiten:
{{ pickup.openingHours }}
{% endif %}

Bitte mitbringen:
- Bestellnummer #{{ orderNumberResolved }}
- Diese E-Mail (optional)

Hinweis:
Zur Abholung genügt es, Ihren Namen zu nennen; die Bezahlung erfolgt im Markt.

Abholhinweise:
Bitte holen Sie die Ware innerhalb von {{ pickup.pickupWindowDays|default(2) }} Tagen ab.

Vielen Dank und bis bald!
Ihr Förde Baumarkt Team
PLAIN
    );

    $this->upsertTemplateTranslation($connection, $templateId, 'en-GB',
    'Your order #{{ orderNumber|default(order is defined ? order.orderNumber|default(\'\') : \'\') }} is ready for pickup',
    <<<HTML
{% set orderEntity = order is defined ? order : null %}
{% set orderNumberResolved = orderNumber|default(orderEntity ? orderEntity.orderNumber|default('') : '') %}
{% set customer = customer|default(orderEntity ? orderEntity.orderCustomer|default({}) : {}) %}
{% set pickupDelivery = orderEntity ? orderEntity.deliveries|first : null %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set fallbackPickup = (pickup is defined and pickup is iterable) ? pickup : config|default({}) %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(fallbackPickup.storeName|default('')),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(fallbackPickup.storeAddress|default('')),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(fallbackPickup.openingHours|default('')),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(fallbackPickup.pickupWindowDays|default(2)),
    'pickupPreparationHours': pickupFields.fb_click_collect_pickup_preparation_hours|default(fallbackPickup.pickupPreparationHours|default(4))
} %}
<p>Hello {{ customer.firstName|default('') }} {{ customer.lastName|default('') }},</p>
<p>Your Click & Collect order <strong>#{{ orderNumberResolved }}</strong> is ready for pickup at our store.</p>
<p><strong>Pickup</strong><br/>{{ pickup.storeName|default('Your store') }}{% if pickup.storeAddress|default('') %}<br/>{{ pickup.storeAddress|nl2br }}{% endif %}{% if pickup.openingHours|default('') %}<br/><em>Opening hours:</em><br/>{{ pickup.openingHours|nl2br }}{% endif %}</p>
<p><strong>Bring</strong></p>
<ul>
    <li>Order number <strong>#{{ orderNumberResolved }}</strong></li>
    <li>This email (optional)</li>
    <li>Stating your name is sufficient; payment happens in store.</li>
    <li>Please collect within <strong>{{ pickup.pickupWindowDays|default(2) }}</strong> days.</li>
</ul>
<p>Thank you and see you soon!<br/>Your Foerde Baumarkt team</p>
HTML,
        <<<PLAIN
{% set orderEntity = order is defined ? order : null %}
{% set orderNumberResolved = orderNumber|default(orderEntity ? orderEntity.orderNumber|default('') : '') %}
{% set customer = customer|default(orderEntity ? orderEntity.orderCustomer|default({}) : {}) %}
{% set pickupDelivery = orderEntity ? orderEntity.deliveries|first : null %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set fallbackPickup = (pickup is defined and pickup is iterable) ? pickup : config|default({}) %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(fallbackPickup.storeName|default('')),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(fallbackPickup.storeAddress|default('')),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(fallbackPickup.openingHours|default('')),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(fallbackPickup.pickupWindowDays|default(2)),
    'pickupPreparationHours': pickupFields.fb_click_collect_pickup_preparation_hours|default(fallbackPickup.pickupPreparationHours|default(4))
} %}
Hello {{ customer.firstName|default('') }} {{ customer.lastName|default('') }}

Your Click & Collect order #{{ orderNumberResolved }} is ready for pickup at our store.

Pickup:
{{ pickup.storeName|default('Your store') }}
{% if pickup.storeAddress|default('') %}{{ pickup.storeAddress }}
{% endif %}{% if pickup.openingHours|default('') %}Opening hours:
{{ pickup.openingHours }}
{% endif %}

Bring:
- Order number #{{ orderNumberResolved }}
- This email (optional)

Note:
Stating your name is sufficient; payment happens in store.

Please collect within {{ pickup.pickupWindowDays|default(2) }} days.

Thank you and see you soon!
Your Foerde Baumarkt team
PLAIN
    );
    }

    public function updateDestructive(Connection $connection): void
    {
        // no-op
    }

    private function insertTypeTranslation(Connection $connection, string $typeId, string $localeCode, string $name): void
    {
        $langId = $this->getLanguageIdByLocaleCode($connection, $localeCode);
        if (!$langId) {
            return;
        }
        $connection->insert('mail_template_type_translation', [
            'mail_template_type_id' => $typeId,
            'language_id' => $langId,
            'name' => $name,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function upsertTemplateTranslation(Connection $connection, string $templateId, string $localeCode, string $subject, string $html, string $plain): void
    {
        $langId = $this->getLanguageIdByLocaleCode($connection, $localeCode);
        if (!$langId) {
            return;
        }
        $existing = $connection->fetchOne(
            'SELECT 1 FROM mail_template_translation WHERE mail_template_id = :tid AND language_id = :lid',
            ['tid' => $templateId, 'lid' => $langId]
        );
        $payload = [
            'mail_template_id' => $templateId,
            'language_id' => $langId,
            'subject' => $subject,
            'content_html' => $html,
            'content_plain' => $plain,
        ];
        if ($existing) {
            $connection->update('mail_template_translation', $payload, [
                'mail_template_id' => $templateId,
                'language_id' => $langId,
            ]);
        } else {
            $connection->insert('mail_template_translation', $payload + [
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function getLanguageIdByLocaleCode(Connection $connection, string $code): ?string
    {
        $sql = 'SELECT language.id FROM language INNER JOIN locale ON language.locale_id = locale.id WHERE locale.code = :code LIMIT 1';
        $id = $connection->fetchOne($sql, ['code' => $code]);
        if (!$id) {
            return null;
        }
        if (is_string($id) && preg_match('/^[0-9a-f]{32}$/i', $id)) {
            return Uuid::fromHexToBytes($id);
        }
        return is_string($id) ? $id : null;
    }
}
