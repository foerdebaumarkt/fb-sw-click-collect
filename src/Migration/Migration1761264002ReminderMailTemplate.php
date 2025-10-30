<?php declare(strict_types=1);

namespace FbClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1761264002ReminderMailTemplate extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        // 2025-10-24 00:00:02 UTC
        return 1761264002;
    }

    public function update(Connection $connection): void
    {
        // Create template type for reminders if missing
        $typeId = $connection->fetchOne('SELECT id FROM mail_template_type WHERE technical_name = :name', [
            'name' => 'fb_click_collect.reminder',
        ]);

        if (!$typeId) {
            $typeId = Uuid::randomBytes();
            $connection->insert('mail_template_type', [
                'id' => $typeId,
                'technical_name' => 'fb_click_collect.reminder',
                'available_entities' => json_encode(['order' => 'order'], JSON_THROW_ON_ERROR),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            $this->insertTypeTranslation($connection, $typeId, 'de-DE', 'Click & Collect: Erinnerung zur Abholung');
            $this->insertTypeTranslation($connection, $typeId, 'en-GB', 'Click & Collect: Pickup reminder');
        } else {
            if (is_string($typeId) && preg_match('/^[0-9a-f]{32}$/i', $typeId)) {
                $typeId = Uuid::fromHexToBytes($typeId);
            }
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

        // Upsert translations (DE/EN)
        $this->upsertTemplateTranslation($connection, $templateId, 'de-DE',
            'Erinnerung: Bitte holen Sie Ihre Bestellung #{{ order.orderNumber }} ab',
            <<<'HTML'
{% set customer = customer|default(order.orderCustomer) %}
{% set pickupDelivery = order.deliveries|first %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(''),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(''),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(''),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(2)
} %}
<p>Hallo {{ customer.firstName|default('') }} {{ customer.lastName|default('') }},</p>
<p>Ihre Click & Collect Bestellung <strong>#{{ order.orderNumber }}</strong> ist abholbereit.</p>
<p>Bitte holen Sie Ihre Bestellung innerhalb von <strong>{{ pickup.pickupWindowDays }}</strong> Tagen ab.</p>
<p><strong>Abholung</strong><br/>{{ pickup.storeName|default('Ihr Markt') }}{% if pickup.storeAddress %}<br/>{{ pickup.storeAddress|nl2br }}{% endif %}{% if pickup.openingHours %}<br/><em>Öffnungszeiten:</em><br/>{{ pickup.openingHours|nl2br }}{% endif %}</p>
<p>Zur Abholung genügt es, Ihren Namen zu nennen; die Bezahlung erfolgt im Markt.</p>
<p>Vielen Dank!<br/>Ihr Baumarkt Team</p>
HTML,
            <<<'PLAIN'
{% set customer = customer|default(order.orderCustomer) %}
{% set pickupDelivery = order.deliveries|first %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(''),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(''),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(''),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(2)
} %}
Hallo {{ customer.firstName|default('') }} {{ customer.lastName|default('') }}

Ihre Click & Collect Bestellung #{{ order.orderNumber }} ist abholbereit.

Bitte holen Sie Ihre Bestellung innerhalb von {{ pickup.pickupWindowDays }} Tagen ab.

Abholung:
{{ pickup.storeName|default('Ihr Markt') }}
{{ pickup.storeAddress }}
{% if pickup.openingHours %}
Öffnungszeiten:
{{ pickup.openingHours }}
{% endif %}

Zur Abholung genügt es, Ihren Namen zu nennen; die Bezahlung erfolgt im Markt.

Vielen Dank!
Ihr Baumarkt Team
PLAIN
        );

        $this->upsertTemplateTranslation($connection, $templateId, 'en-GB',
            'Reminder: Please pick up your order #{{ order.orderNumber }}',
            <<<'HTML'
{% set customer = customer|default(order.orderCustomer) %}
{% set pickupDelivery = order.deliveries|first %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(''),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(''),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(''),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(2)
} %}
<p>Hello {{ customer.firstName|default('') }} {{ customer.lastName|default('') }},</p>
<p>Your Click & Collect order <strong>#{{ order.orderNumber }}</strong> is ready for pickup.</p>
<p>Please collect your order within <strong>{{ pickup.pickupWindowDays }}</strong> days.</p>
<p><strong>Pickup</strong><br/>{{ pickup.storeName|default('Your store') }}{% if pickup.storeAddress %}<br/>{{ pickup.storeAddress|nl2br }}{% endif %}{% if pickup.openingHours %}<br/><em>Opening hours:</em><br/>{{ pickup.openingHours|nl2br }}{% endif %}</p>
<p>Stating your name is sufficient; payment happens in store.</p>
<p>Thank you!<br/>Your Baumarkt team</p>
HTML,
            <<<'PLAIN'
{% set customer = customer|default(order.orderCustomer) %}
{% set pickupDelivery = order.deliveries|first %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(''),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(''),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(''),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(2)
} %}
Hello {{ customer.firstName|default('') }} {{ customer.lastName|default('') }}

Your Click & Collect order #{{ order.orderNumber }} is ready for pickup.

Please collect your order within {{ pickup.pickupWindowDays }} days.

Pickup:
{{ pickup.storeName|default('Your store') }}
{{ pickup.storeAddress }}
{% if pickup.openingHours %}
Opening hours:
{{ pickup.openingHours }}
{% endif %}

Stating your name is sufficient; payment happens in store.

Thank you!
Your Baumarkt team
PLAIN
        );
    }

    public function updateDestructive(Connection $connection): void
    {
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
