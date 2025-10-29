<?php declare(strict_types=1);

namespace FbClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1761264003OrderConfirmationMailTemplate extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        // 2025-10-24 00:00:03 UTC
        return 1761264003;
    }

    public function update(Connection $connection): void
    {
        $typeId = $connection->fetchOne('SELECT id FROM mail_template_type WHERE technical_name = :name', [
            'name' => 'fb_click_collect.order_confirmation',
        ]);

        if (!$typeId) {
            $typeId = Uuid::randomBytes();
            $connection->insert('mail_template_type', [
                'id' => $typeId,
                'technical_name' => 'fb_click_collect.order_confirmation',
                'available_entities' => json_encode([
                    'order' => 'order',
                    'order_transaction' => 'order_transaction',
                    'order_delivery' => 'order_delivery',
                    'order_customer' => 'order_customer',
                    'customer' => 'customer',
                    'sales_channel' => 'sales_channel',
                    'billing_address' => 'order_address',
                    'shipping_address' => 'order_address',
                ], JSON_THROW_ON_ERROR),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            $this->insertTypeTranslation($connection, $typeId, 'de-DE', 'Click & Collect: Bestellbestätigung');
            $this->insertTypeTranslation($connection, $typeId, 'en-GB', 'Click & Collect: Order confirmation');
        } elseif (is_string($typeId) && preg_match('/^[0-9a-f]{32}$/i', $typeId)) {
            $typeId = Uuid::fromHexToBytes($typeId);
        }

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
        } elseif (is_string($templateId) && preg_match('/^[0-9a-f]{32}$/i', $templateId)) {
            $templateId = Uuid::fromHexToBytes($templateId);
        }

    $this->upsertTemplateTranslation($connection, $templateId, 'de-DE',
        'Ihre Click & Collect Bestellung #{{ order.orderNumber }} ist eingegangen',
        <<<HTML
{% set pickupDelivery = order.deliveries|first %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set fallbackPickup = clickCollectPickup|default({}) %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(fallbackPickup.storeName|default('')),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(fallbackPickup.storeAddress|default('')),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(fallbackPickup.openingHours|default('')),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(fallbackPickup.pickupWindowDays|default(2)),
    'pickupPreparationHours': pickupFields.fb_click_collect_pickup_preparation_hours|default(fallbackPickup.pickupPreparationHours|default(4))
} %}
<p>Hallo {{ order.orderCustomer.firstName|default('') }} {{ order.orderCustomer.lastName|default('') }},</p>
<p>vielen Dank für Ihre Click &amp; Collect Bestellung <strong>#{{ order.orderNumber }}</strong>.</p>
<p>Wir bereiten Ihre Artikel vor. Sobald sie abholbereit sind, erhalten Sie eine weitere E-Mail.</p>
<p><strong>Abholung (nach Benachrichtigung)</strong><br/>{{ pickup.storeName|default('Ihr Markt') }}<br/>{{ pickup.storeAddress|default('')|nl2br }}</p>
{% if pickup.openingHours|default('') %}<p><em>Öffnungszeiten:</em><br/>{{ pickup.openingHours|nl2br }}</p>{% endif %}
<p>Abholfenster: {{ pickup.pickupWindowDays|default(2) }} Tage - Vorbereitung: ca. {{ pickup.pickupPreparationHours|default(4) }} Stunden.</p>
<p>Eine Übersicht Ihrer Bestellung finden Sie jederzeit im Kundenkonto.</p>
<p>Ihr Förde Baumarkt Team</p>
HTML,
        <<<PLAIN
{% set pickupDelivery = order.deliveries|first %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set fallbackPickup = clickCollectPickup|default({}) %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(fallbackPickup.storeName|default('')),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(fallbackPickup.storeAddress|default('')),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(fallbackPickup.openingHours|default('')),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(fallbackPickup.pickupWindowDays|default(2)),
    'pickupPreparationHours': pickupFields.fb_click_collect_pickup_preparation_hours|default(fallbackPickup.pickupPreparationHours|default(4))
} %}
Hallo {{ order.orderCustomer.firstName|default('') }} {{ order.orderCustomer.lastName|default('') }},

vielen Dank für Ihre Click & Collect Bestellung #{{ order.orderNumber }}.

Wir bereiten Ihre Artikel vor und informieren Sie per E-Mail, sobald die Abholung möglich ist.

Abholung (nach Benachrichtigung):
{{ pickup.storeName|default('Ihr Markt') }}
{{ pickup.storeAddress|default('') }}
{% if pickup.openingHours|default('') %}
Öffnungszeiten:
{{ pickup.openingHours }}
{% endif %}

Abholfenster: {{ pickup.pickupWindowDays|default(2) }} Tage
Vorbereitung: ca. {{ pickup.pickupPreparationHours|default(4) }} Stunden

Sie finden Ihre Bestellung jederzeit im Kundenkonto.

Ihr Förde Baumarkt Team
PLAIN
    );

    $this->upsertTemplateTranslation($connection, $templateId, 'en-GB',
        'Your Click & Collect order #{{ order.orderNumber }} has been received',
        <<<HTML
{% set pickupDelivery = order.deliveries|first %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set fallbackPickup = clickCollectPickup|default({}) %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(fallbackPickup.storeName|default('')),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(fallbackPickup.storeAddress|default('')),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(fallbackPickup.openingHours|default('')),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(fallbackPickup.pickupWindowDays|default(2)),
    'pickupPreparationHours': pickupFields.fb_click_collect_pickup_preparation_hours|default(fallbackPickup.pickupPreparationHours|default(4))
} %}
<p>Hello {{ order.orderCustomer.firstName|default('') }} {{ order.orderCustomer.lastName|default('') }},</p>
<p>thank you for your Click &amp; Collect order <strong>#{{ order.orderNumber }}</strong>.</p>
<p>We are preparing your items. You will receive another email once they are ready for pickup.</p>
<p><strong>Pickup (after notification)</strong><br/>{{ pickup.storeName|default('Your store') }}<br/>{{ pickup.storeAddress|default('')|nl2br }}</p>
{% if pickup.openingHours|default('') %}<p><em>Opening hours:</em><br/>{{ pickup.openingHours|nl2br }}</p>{% endif %}
<p>Pickup window: {{ pickup.pickupWindowDays|default(2) }} days - Preparation time: approx. {{ pickup.pickupPreparationHours|default(4) }} hours.</p>
<p>You can review your order details in your customer account at any time.</p>
<p>Your Förde Baumarkt Team</p>
HTML,
        <<<PLAIN
{% set pickupDelivery = order.deliveries|first %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set fallbackPickup = clickCollectPickup|default({}) %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(fallbackPickup.storeName|default('')),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(fallbackPickup.storeAddress|default('')),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(fallbackPickup.openingHours|default('')),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(fallbackPickup.pickupWindowDays|default(2)),
    'pickupPreparationHours': pickupFields.fb_click_collect_pickup_preparation_hours|default(fallbackPickup.pickupPreparationHours|default(4))
} %}
Hello {{ order.orderCustomer.firstName|default('') }} {{ order.orderCustomer.lastName|default('') }},

thank you for your Click & Collect order #{{ order.orderNumber }}.

We are preparing your items and will notify you once pickup is possible.

Pickup (after notification):
{{ pickup.storeName|default('Your store') }}
{{ pickup.storeAddress|default('') }}
{% if pickup.openingHours|default('') %}
Opening hours:
{{ pickup.openingHours }}
{% endif %}

Pickup window: {{ pickup.pickupWindowDays|default(2) }} days
Preparation time: approx. {{ pickup.pickupPreparationHours|default(4) }} hours

You can view your order anytime in your customer account.

Your Förde Baumarkt Team
PLAIN
    );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function insertTypeTranslation(Connection $connection, string $typeId, string $localeCode, string $name): void
    {
        $languageId = $this->getLanguageIdByLocaleCode($connection, $localeCode);
        if (!$languageId) {
            return;
        }

        $connection->insert('mail_template_type_translation', [
            'mail_template_type_id' => $typeId,
            'language_id' => $languageId,
            'name' => $name,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function upsertTemplateTranslation(Connection $connection, string $templateId, string $localeCode, string $subject, string $html, string $plain): void
    {
        $languageId = $this->getLanguageIdByLocaleCode($connection, $localeCode);
        if (!$languageId) {
            return;
        }

        $exists = $connection->fetchOne(
            'SELECT 1 FROM mail_template_translation WHERE mail_template_id = :mid AND language_id = :lid',
            ['mid' => $templateId, 'lid' => $languageId]
        );

        $payload = [
            'mail_template_id' => $templateId,
            'language_id' => $languageId,
            'subject' => $subject,
            'content_html' => $html,
            'content_plain' => $plain,
        ];

        if ($exists) {
            $connection->update('mail_template_translation', $payload, [
                'mail_template_id' => $templateId,
                'language_id' => $languageId,
            ]);
        } else {
            $connection->insert('mail_template_translation', $payload + [
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function getLanguageIdByLocaleCode(Connection $connection, string $localeCode): ?string
    {
        $id = $connection->fetchOne(
            'SELECT language.id FROM language INNER JOIN locale ON language.locale_id = locale.id WHERE locale.code = :code LIMIT 1',
            ['code' => $localeCode]
        );

        if (!$id) {
            return null;
        }

        if (is_string($id) && preg_match('/^[0-9a-f]{32}$/i', $id)) {
            return Uuid::fromHexToBytes($id);
        }

        return is_string($id) ? $id : null;
    }
}
