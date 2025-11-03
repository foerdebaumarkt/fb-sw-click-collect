<?php declare(strict_types=1);

namespace FbClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1761264400StaffMailTemplate extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        // 2025-10-24 00:06:40 UTC
        return 1761264400;
    }

    public function update(Connection $connection): void
    {
        $typeName = 'fb_click_collect.staff_order_placed';
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $defaultLanguageId = hex2bin('2fbb5fe2e29a4d70aa5854ce7ce3e20b');
        $enLanguageId = $this->getLanguageId($connection, 'en-GB');
        $deLanguageId = $this->getLanguageId($connection, 'de-DE');

        $typeId = $connection->fetchOne('SELECT id FROM mail_template_type WHERE technical_name = :name', [
            'name' => $typeName,
        ]);

        if (!$typeId) {
            $typeId = $this->randomBinaryId();
            $availableEntities = json_encode([
                'order' => 'order',
                'order_delivery' => 'order_delivery',
                'sales_channel' => 'sales_channel',
                'customer' => 'customer',
            ], JSON_THROW_ON_ERROR);

            $connection->insert('mail_template_type', [
                'id' => $typeId,
                'technical_name' => $typeName,
                'available_entities' => $availableEntities,
                'created_at' => $now,
            ]);
        } elseif (\is_string($typeId) && \preg_match('/^[0-9a-f]{32}$/i', $typeId)) {
            $typeId = \hex2bin($typeId);
        }

        // Upsert translations (always update to ensure correct German text)
        if ($deLanguageId) {
            $this->upsertTypeTranslation($connection, $typeId, $deLanguageId, 'Click & Collect: Team-Benachrichtigung', $now);
        }
        if ($enLanguageId) {
            $this->upsertTypeTranslation($connection, $typeId, $enLanguageId, 'Click & Collect: Staff notification', $now);
        }
        // Fallback for system default if it's neither de nor en
        if ($defaultLanguageId !== $deLanguageId && $defaultLanguageId !== $enLanguageId) {
            $this->upsertTypeTranslation($connection, $typeId, $defaultLanguageId, 'Click & Collect: Staff notification', $now);
        }

        $templateId = $connection->fetchOne('SELECT id FROM mail_template WHERE mail_template_type_id = :typeId ORDER BY created_at ASC LIMIT 1', [
            'typeId' => $typeId,
        ]);

        if (!$templateId) {
            $templateId = $this->randomBinaryId();
            $connection->insert('mail_template', [
                'id' => $templateId,
                'mail_template_type_id' => $typeId,
                'system_default' => 1,
                'created_at' => $now,
            ]);
        } elseif (\is_string($templateId) && \preg_match('/^[0-9a-f]{32}$/i', $templateId)) {
            $templateId = \hex2bin($templateId);
        }

        $connection->update('mail_template', [
            'system_default' => 1,
        ], [
            'id' => $templateId,
        ]);

        $htmlDe = <<<HTML
<p>Hallo Team,</p>
<p>es liegt eine neue Click &amp; Collect Bestellung <strong>#{{ order.orderNumber }}</strong> vor, die als Abholung im Markt markiert ist.</p>
<h3>Kundendaten</h3>
{% set customer = order.orderCustomer %}
<p>{% if customer %}{{ customer.firstName }} {{ customer.lastName }}<br/>{{ customer.email }}{% else %}–{% endif %}</p>
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
<h3>Abholhinweise</h3>
<p>Bitte bereitet die Bestellung innerhalb von <strong>{{ pickup.pickupPreparationHours|default(4) }}</strong> Stunden vor. Die Abholung ist für <strong>{{ pickup.pickupWindowDays|default(2) }}</strong> Tage möglich.</p>
<h3>Markt</h3>
<p>{% if pickup.storeName|default('') %}<strong>{{ pickup.storeName }}</strong><br/>{% endif %}
{% if pickup.storeAddress|default('') %}{{ pickup.storeAddress|nl2br }}<br/>{% endif %}
{% if pickup.openingHours|default('') %}<em>Öffnungszeiten:</em><br/>{{ pickup.openingHours|nl2br }}{% endif %}</p>
<hr/>
<p style="color:#666; font-size:12px;">Diese E-Mail wurde automatisch vom Click &amp; Collect Plugin generiert.</p>
HTML;

        $textDe = <<<TEXT
Hallo Team,

es liegt eine neue Click & Collect Bestellung #{{ order.orderNumber }} vor, die als Abholung im Markt markiert ist.

Kundendaten:
{% set customer = order.orderCustomer %}{% if customer %}{{ customer.firstName }} {{ customer.lastName }} / {{ customer.email }}{% else %}–{% endif %}

Bestellpositionen:
{% for item in order.lineItems %}- {{ item.label }} ({{ item.quantity }} × {{ item.price.unitPrice|number_format(2, ',', '.') }} €)
{% endfor %}
Gesamtsumme: {{ order.amountTotal|number_format(2, ',', '.') }} €

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

Abholhinweise:
- Vorbereitung: {{ pickup.pickupPreparationHours|default(4) }} Stunden
- Abholung möglich für: {{ pickup.pickupWindowDays|default(2) }} Tage

Markt:
{% if pickup.storeName|default('') %}{{ pickup.storeName }}
{% endif %}{% if pickup.storeAddress|default('') %}{{ pickup.storeAddress }}
{% endif %}{% if pickup.openingHours|default('') %}Öffnungszeiten:
{{ pickup.openingHours }}
{% endif %}

Diese E-Mail wurde automatisch vom Click & Collect Plugin generiert.
TEXT;

        $htmlEn = <<<HTML
<p>Hello team,</p>
<p>A new Click &amp; Collect order <strong>#{{ order.orderNumber }}</strong> has been placed and is marked for in-store pickup.</p>
<h3>Customer</h3>
{% set customer = order.orderCustomer %}
<p>{% if customer %}{{ customer.firstName }} {{ customer.lastName }}<br/>{{ customer.email }}{% else %}–{% endif %}</p>
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
<h3>Pickup details</h3>
<p>Please prepare the order within <strong>{{ pickup.pickupPreparationHours|default(4) }}</strong> hours. Pickup is available for <strong>{{ pickup.pickupWindowDays|default(2) }}</strong> days.</p>
<h3>Store</h3>
<p>{% if pickup.storeName|default('') %}<strong>{{ pickup.storeName }}</strong><br/>{% endif %}
{% if pickup.storeAddress|default('') %}{{ pickup.storeAddress|nl2br }}<br/>{% endif %}
{% if pickup.openingHours|default('') %}<em>Opening hours:</em><br/>{{ pickup.openingHours|nl2br }}{% endif %}</p>
<hr/>
<p style="color:#666; font-size:12px;">This email was generated automatically by the Click & Collect plugin.</p>
HTML;

        $textEn = <<<TEXT
Hello team,

A new Click & Collect order #{{ order.orderNumber }} has been placed and is marked for in-store pickup.

Customer:
{% set customer = order.orderCustomer %}{% if customer %}{{ customer.firstName }} {{ customer.lastName }} / {{ customer.email }}{% else %}–{% endif %}

Items:
{% for item in order.lineItems %}- {{ item.label }} ({{ item.quantity }} × {{ item.price.unitPrice|number_format(2, '.', ',') }} €)
{% endfor %}
Total: {{ order.amountTotal|number_format(2, '.', ',') }} €

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

Pickup details:
- Preparation: {{ pickup.pickupPreparationHours|default(4) }} hours
- Pickup window: {{ pickup.pickupWindowDays|default(2) }} days

Store:
{% if pickup.storeName|default('') %}{{ pickup.storeName }}
{% endif %}{% if pickup.storeAddress|default('') %}{{ pickup.storeAddress }}
{% endif %}{% if pickup.openingHours|default('') %}Opening hours:
{{ pickup.openingHours }}
{% endif %}

This email was generated automatically by the Click & Collect plugin.
TEXT;

    $this->upsertTemplateTranslation($connection, $templateId, $defaultLanguageId, 'New Click & Collect order #{{ order.orderNumber }}', $htmlEn, $textEn, $now);
        if ($enLanguageId && $enLanguageId !== $defaultLanguageId) {
      $this->upsertTemplateTranslation($connection, $templateId, $enLanguageId, 'New Click & Collect order #{{ order.orderNumber }}', $htmlEn, $textEn, $now);
        }
        if ($deLanguageId) {
      $this->upsertTemplateTranslation($connection, $templateId, $deLanguageId, 'Neue Click & Collect Bestellung #{{ order.orderNumber }}', $htmlDe, $textDe, $now);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // no-op
    }

    private function getLanguageId(Connection $connection, string $localeCode): ?string
    {
        $localeId = $connection->fetchOne('SELECT id FROM locale WHERE code = :code', ['code' => $localeCode]);
        if (!$localeId) {
            return null;
        }

        $languageId = $connection->fetchOne('SELECT id FROM language WHERE locale_id = :lid OR translation_code_id = :lid LIMIT 1', ['lid' => $localeId]);
        if (!$languageId) {
            return null;
        }

        if (\is_string($languageId) && \preg_match('/^[0-9a-f]{32}$/i', $languageId)) {
            return \hex2bin($languageId);
        }

        return \is_string($languageId) ? $languageId : null;
    }

    private function upsertTypeTranslation(Connection $connection, string $typeId, string $languageId, string $name, string $createdAt): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM mail_template_type_translation WHERE mail_template_type_id = :tid AND language_id = :lid',
            ['tid' => $typeId, 'lid' => $languageId]
        );

        if ($exists) {
            $connection->update('mail_template_type_translation', [
                'name' => $name,
            ], [
                'mail_template_type_id' => $typeId,
                'language_id' => $languageId,
            ]);
        } else {
            $connection->insert('mail_template_type_translation', [
                'mail_template_type_id' => $typeId,
                'language_id' => $languageId,
                'name' => $name,
                'created_at' => $createdAt,
            ]);
        }
    }

    private function upsertTemplateTranslation(Connection $connection, string $templateId, string $languageId, string $subject, string $html, string $plain, string $createdAt): void
    {
        $exists = $connection->fetchOne('SELECT 1 FROM mail_template_translation WHERE mail_template_id = :tid AND language_id = :lid', [
            'tid' => $templateId,
            'lid' => $languageId,
        ]);

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
            $payload['created_at'] = $createdAt;
            $connection->insert('mail_template_translation', $payload);
        }
    }

    private function randomBinaryId(): string
    {
        return \hex2bin(bin2hex(random_bytes(16)));
    }
}
