<?php declare(strict_types=1);

namespace FbClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1761264001StaffMailTemplate extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1761264001;
    }

    public function update(Connection $connection): void
    {
        $technicalName = 'fb_click_collect.staff_order_placed';

        // Check if mail template type already exists (legacy from Foerdebaumarkt plugin)
        $existingTypeId = $connection->fetchOne(
            'SELECT id FROM mail_template_type WHERE technical_name = :name',
            ['name' => $technicalName]
        );

        if ($existingTypeId) {
            // Template type already exists (legacy data), skip creation
            return;
        }

        $typeId = Uuid::randomBytes();
        $templateId = Uuid::randomBytes();
        $langEn = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        $langDeHex = $this->getLanguageId($connection, 'de-DE');
        $langDe = $langDeHex ? Uuid::fromHexToBytes($langDeHex) : $langEn;

        // Create mail template type
        $connection->insert('mail_template_type', [
            'id' => $typeId,
            'technical_name' => $technicalName,
            'available_entities' => json_encode([
                'order' => 'order',
                'order_delivery' => 'order_delivery',
                'sales_channel' => 'sales_channel',
                'customer' => 'customer',
            ]),
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        // Type translations
        $connection->insert('mail_template_type_translation', [
            'mail_template_type_id' => $typeId,
            'language_id' => $langEn,
            'name' => 'Click & Collect: Staff notification',
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('mail_template_type_translation', [
            'mail_template_type_id' => $typeId,
            'language_id' => $langDe,
            'name' => 'Click & Collect: Team-Benachrichtigung',
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        // Create mail template
        $connection->insert('mail_template', [
            'id' => $templateId,
            'mail_template_type_id' => $typeId,
            'system_default' => 1,
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        // English translation
        $subjectEn = 'New Click & Collect order #{{ order.orderNumber }}';
        $htmlEn = <<<'HTML'
{% set pickupDelivery = order.deliveries|first %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(''),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(''),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(''),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(2),
    'pickupPreparationHours': pickupFields.fb_click_collect_pickup_preparation_hours|default(4)
} %}
<p>Hello team,</p>
<p>A new Click & Collect order <strong>#{{ order.orderNumber }}</strong> has been placed and marked for in-store pickup.</p>
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
<p>Please prepare the order within <strong>{{ pickup.pickupPreparationHours }}</strong> hours. Pickup is available for <strong>{{ pickup.pickupWindowDays }}</strong> days.</p>
<h3>Store</h3>
<p>{% if pickup.storeName %}<strong>{{ pickup.storeName }}</strong><br/>{% endif %}
{% if pickup.storeAddress %}{{ pickup.storeAddress|nl2br }}<br/>{% endif %}
{% if pickup.openingHours %}<em>Opening hours:</em><br/>{{ pickup.openingHours|nl2br }}{% endif %}</p>
<hr/>
<p style="color:#666; font-size:12px;">This email was generated automatically by the Click & Collect plugin.</p>
HTML;

        $plainEn = <<<'TEXT'
{% set pickupDelivery = order.deliveries|first %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(''),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(''),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(''),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(2),
    'pickupPreparationHours': pickupFields.fb_click_collect_pickup_preparation_hours|default(4)
} %}
Hello team,

A new Click & Collect order #{{ order.orderNumber }} has been placed and marked for in-store pickup.

Customer:
{% set oc = order.orderCustomer %}{% if oc %}{{ oc.firstName }} {{ oc.lastName }} / {{ oc.email }}{% else %}–{% endif %}

Items:
{% for item in order.lineItems %}- {{ item.label }} ({{ item.quantity }} × {{ item.price.unitPrice|number_format(2, '.', ',') }} €)
{% endfor %}
Total: {{ order.amountTotal|number_format(2, '.', ',') }} €

Pickup details:
- Preparation: {{ pickup.pickupPreparationHours }} hours
- Pickup window: {{ pickup.pickupWindowDays }} days

Store:
{% if pickup.storeName %}{{ pickup.storeName }}
{% endif %}{% if pickup.storeAddress %}{{ pickup.storeAddress }}
{% endif %}{% if pickup.openingHours %}Opening hours:
{{ pickup.openingHours }}
{% endif %}

This email was generated automatically by the Click & Collect plugin.
TEXT;

        $connection->insert('mail_template_translation', [
            'mail_template_id' => $templateId,
            'language_id' => $langEn,
            'subject' => $subjectEn,
            'content_html' => $htmlEn,
            'content_plain' => $plainEn,
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        // German translation
        $subjectDe = 'Neue Click & Collect Bestellung #{{ order.orderNumber }}';
        $htmlDe = <<<'HTML'
{% set pickupDelivery = order.deliveries|first %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(''),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(''),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(''),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(2),
    'pickupPreparationHours': pickupFields.fb_click_collect_pickup_preparation_hours|default(4)
} %}
<p>Hallo Team,</p>
<p>es liegt eine neue Click & Collect Bestellung <strong>#{{ order.orderNumber }}</strong> vor, die als Abholung im Markt markiert ist.</p>
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
<p>Bitte bereitet die Bestellung innerhalb von <strong>{{ pickup.pickupPreparationHours }}</strong> Stunden vor. Die Abholung ist für <strong>{{ pickup.pickupWindowDays }}</strong> Tage möglich.</p>
<h3>Markt</h3>
<p>{% if pickup.storeName %}<strong>{{ pickup.storeName }}</strong><br/>{% endif %}
{% if pickup.storeAddress %}{{ pickup.storeAddress|nl2br }}<br/>{% endif %}
{% if pickup.openingHours %}<em>Öffnungszeiten:</em><br/>{{ pickup.openingHours|nl2br }}{% endif %}</p>
<hr/>
<p style="color:#666; font-size:12px;">Diese E-Mail wurde automatisch vom Click & Collect Plugin generiert.</p>
HTML;

        $plainDe = <<<'TEXT'
{% set pickupDelivery = order.deliveries|first %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set pickup = {
    'storeName': pickupFields.fb_click_collect_store_name|default(''),
    'storeAddress': pickupFields.fb_click_collect_store_address|default(''),
    'openingHours': pickupFields.fb_click_collect_opening_hours|default(''),
    'pickupWindowDays': pickupFields.fb_click_collect_pickup_window_days|default(2),
    'pickupPreparationHours': pickupFields.fb_click_collect_pickup_preparation_hours|default(4)
} %}
Hallo Team,

es liegt eine neue Click & Collect Bestellung #{{ order.orderNumber }} vor, die als Abholung im Markt markiert ist.

Kundendaten:
{% set oc = order.orderCustomer %}{% if oc %}{{ oc.firstName }} {{ oc.lastName }} / {{ oc.email }}{% else %}–{% endif %}

Bestellpositionen:
{% for item in order.lineItems %}- {{ item.label }} ({{ item.quantity }} × {{ item.price.unitPrice|number_format(2, ',', '.') }} €)
{% endfor %}
Gesamtsumme: {{ order.amountTotal|number_format(2, ',', '.') }} €

Abholhinweise:
- Vorbereitung: {{ pickup.pickupPreparationHours }} Stunden
- Abholung möglich für: {{ pickup.pickupWindowDays }} Tage

Markt:
{% if pickup.storeName %}{{ pickup.storeName }}
{% endif %}{% if pickup.storeAddress %}{{ pickup.storeAddress }}
{% endif %}{% if pickup.openingHours %}Öffnungszeiten:
{{ pickup.openingHours }}
{% endif %}

Diese E-Mail wurde automatisch vom Click & Collect Plugin generiert.
TEXT;

        $connection->insert('mail_template_translation', [
            'mail_template_id' => $templateId,
            'language_id' => $langDe,
            'subject' => $subjectDe,
            'content_html' => $htmlDe,
            'content_plain' => $plainDe,
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes needed
    }

    private function getLanguageId(Connection $connection, string $locale): ?string
    {
        $sql = <<<'SQL'
            SELECT LOWER(HEX(language.id))
            FROM language
            INNER JOIN locale ON locale.id = language.locale_id
            WHERE locale.code = :code
        SQL;

        $languageId = $connection->fetchOne($sql, ['code' => $locale]);

        return $languageId ?: null;
    }
}
