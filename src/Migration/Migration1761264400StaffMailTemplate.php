<?php declare(strict_types=1);

namespace FoerdeClickCollect\Migration;

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

            $this->insertTypeTranslation($connection, $typeId, $defaultLanguageId, 'Click & Collect: Staff notification', $now);
            if ($enLanguageId && $enLanguageId !== $defaultLanguageId) {
                $this->insertTypeTranslation($connection, $typeId, $enLanguageId, 'Click & Collect: Staff notification', $now);
            }
            if ($deLanguageId) {
                $this->insertTypeTranslation($connection, $typeId, $deLanguageId, 'Click & Collect: Team-Benachrichtigung', $now);
            }
        } elseif (\is_string($typeId) && \preg_match('/^[0-9a-f]{32}$/i', $typeId)) {
            $typeId = \hex2bin($typeId);
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
<p>es liegt eine neue Click &amp; Collect Bestellung <strong>#{{ orderNumber }}</strong> vor, die als Abholung im Markt markiert ist.</p>
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
<h3>Abholhinweise</h3>
<p>Bitte bereitet die Bestellung innerhalb von <strong>{{ config.pickupPreparationHours }}</strong> Stunden vor. Die Abholung ist für <strong>{{ config.pickupWindowDays }}</strong> Tage möglich.</p>
<h3>Markt</h3>
<p>{% if config.storeName %}<strong>{{ config.storeName }}</strong><br/>{% endif %}
{% if config.storeAddress %}{{ config.storeAddress|nl2br }}<br/>{% endif %}
{% if config.openingHours %}<em>Öffnungszeiten:</em><br/>{{ config.openingHours|nl2br }}{% endif %}</p>
<hr/>
<p style="color:#666; font-size:12px;">Diese E-Mail wurde automatisch vom Click &amp; Collect Plugin generiert.</p>
HTML;

        $textDe = <<<TEXT
Hallo Team,

es liegt eine neue Click & Collect Bestellung #{{ orderNumber }} vor, die als Abholung im Markt markiert ist.

Kundendaten:
{% set customer = order.orderCustomer %}{% if customer %}{{ customer.firstName }} {{ customer.lastName }} / {{ customer.email }}{% else %}–{% endif %}

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

        $htmlEn = <<<HTML
<p>Hello team,</p>
<p>A new Click &amp; Collect order <strong>#{{ orderNumber }}</strong> has been placed and is marked for in-store pickup.</p>
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
<h3>Pickup details</h3>
<p>Please prepare the order within <strong>{{ config.pickupPreparationHours }}</strong> hours. Pickup is available for <strong>{{ config.pickupWindowDays }}</strong> days.</p>
<h3>Store</h3>
<p>{% if config.storeName %}<strong>{{ config.storeName }}</strong><br/>{% endif %}
{% if config.storeAddress %}{{ config.storeAddress|nl2br }}<br/>{% endif %}
{% if config.openingHours %}<em>Opening hours:</em><br/>{{ config.openingHours|nl2br }}{% endif %}</p>
<hr/>
<p style="color:#666; font-size:12px;">This email was generated automatically by the Click & Collect plugin.</p>
HTML;

        $textEn = <<<TEXT
Hello team,

A new Click & Collect order #{{ orderNumber }} has been placed and is marked for in-store pickup.

Customer:
{% set customer = order.orderCustomer %}{% if customer %}{{ customer.firstName }} {{ customer.lastName }} / {{ customer.email }}{% else %}–{% endif %}

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

        $this->upsertTemplateTranslation($connection, $templateId, $defaultLanguageId, 'New Click & Collect order #{{ orderNumber }}', $htmlEn, $textEn, $now);
        if ($enLanguageId && $enLanguageId !== $defaultLanguageId) {
            $this->upsertTemplateTranslation($connection, $templateId, $enLanguageId, 'New Click & Collect order #{{ orderNumber }}', $htmlEn, $textEn, $now);
        }
        if ($deLanguageId) {
            $this->upsertTemplateTranslation($connection, $templateId, $deLanguageId, 'Neue Click & Collect Bestellung #{{ orderNumber }}', $htmlDe, $textDe, $now);
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

    private function insertTypeTranslation(Connection $connection, string $typeId, string $languageId, string $name, string $createdAt): void
    {
        $connection->insert('mail_template_type_translation', [
            'mail_template_type_id' => $typeId,
            'language_id' => $languageId,
            'name' => $name,
            'created_at' => $createdAt,
        ]);
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
