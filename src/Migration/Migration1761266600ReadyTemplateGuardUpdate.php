<?php declare(strict_types=1);

namespace FoerdeClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1761266600ReadyTemplateGuardUpdate extends MigrationStep
{
    private const TYPE_NAME = 'fb_click_collect.ready';

    private const OLD_SUBJECT_DE = 'Ihre Bestellung #{{ orderNumber }} ist abholbereit';
    private const OLD_SUBJECT_EN = 'Your order #{{ orderNumber }} is ready for pickup';

    private const NEW_SUBJECT_DE = 'Ihre Bestellung #{{ orderNumber|default(order is defined ? order.orderNumber|default(\'\') : \'\') }} ist abholbereit';
    private const NEW_SUBJECT_EN = 'Your order #{{ orderNumber|default(order is defined ? order.orderNumber|default(\'\') : \'\') }} is ready for pickup';

    private const OLD_GUARD_MARKER = 'customer|default(order.orderCustomer)';

    private const NEW_HTML_DE = <<<'HTML'
{% set orderEntity = order is defined ? order : null %}
{% set orderNumberResolved = orderNumber|default(orderEntity ? orderEntity.orderNumber|default('') : '') %}
{% set customer = customer|default(orderEntity ? orderEntity.orderCustomer|default({}) : {}) %}
{% set pickupDelivery = orderEntity ? orderEntity.deliveries|first : null %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set fallbackPickup = (pickup is defined and pickup is iterable) ? pickup : config|default({}) %}
{% set pickup = {
        'storeName': pickupFields.foerde_click_collect_store_name|default(fallbackPickup.storeName|default('')),
        'storeAddress': pickupFields.foerde_click_collect_store_address|default(fallbackPickup.storeAddress|default('')),
        'openingHours': pickupFields.foerde_click_collect_opening_hours|default(fallbackPickup.openingHours|default('')),
        'pickupWindowDays': pickupFields.foerde_click_collect_pickup_window_days|default(fallbackPickup.pickupWindowDays|default(2)),
        'pickupPreparationHours': pickupFields.foerde_click_collect_pickup_preparation_hours|default(fallbackPickup.pickupPreparationHours|default(4))
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
HTML;

    private const NEW_HTML_EN = <<<'HTML'
{% set orderEntity = order is defined ? order : null %}
{% set orderNumberResolved = orderNumber|default(orderEntity ? orderEntity.orderNumber|default('') : '') %}
{% set customer = customer|default(orderEntity ? orderEntity.orderCustomer|default({}) : {}) %}
{% set pickupDelivery = orderEntity ? orderEntity.deliveries|first : null %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set fallbackPickup = (pickup is defined and pickup is iterable) ? pickup : config|default({}) %}
{% set pickup = {
        'storeName': pickupFields.foerde_click_collect_store_name|default(fallbackPickup.storeName|default('')),
        'storeAddress': pickupFields.foerde_click_collect_store_address|default(fallbackPickup.storeAddress|default('')),
        'openingHours': pickupFields.foerde_click_collect_opening_hours|default(fallbackPickup.openingHours|default('')),
        'pickupWindowDays': pickupFields.foerde_click_collect_pickup_window_days|default(fallbackPickup.pickupWindowDays|default(2)),
        'pickupPreparationHours': pickupFields.foerde_click_collect_pickup_preparation_hours|default(fallbackPickup.pickupPreparationHours|default(4))
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
HTML;

    private const NEW_PLAIN_DE = <<<'TEXT'
{% set orderEntity = order is defined ? order : null %}
{% set orderNumberResolved = orderNumber|default(orderEntity ? orderEntity.orderNumber|default('') : '') %}
{% set customer = customer|default(orderEntity ? orderEntity.orderCustomer|default({}) : {}) %}
{% set pickupDelivery = orderEntity ? orderEntity.deliveries|first : null %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set fallbackPickup = (pickup is defined and pickup is iterable) ? pickup : config|default({}) %}
{% set pickup = {
        'storeName': pickupFields.foerde_click_collect_store_name|default(fallbackPickup.storeName|default('')),
        'storeAddress': pickupFields.foerde_click_collect_store_address|default(fallbackPickup.storeAddress|default('')),
        'openingHours': pickupFields.foerde_click_collect_opening_hours|default(fallbackPickup.openingHours|default('')),
        'pickupWindowDays': pickupFields.foerde_click_collect_pickup_window_days|default(fallbackPickup.pickupWindowDays|default(2)),
        'pickupPreparationHours': pickupFields.foerde_click_collect_pickup_preparation_hours|default(fallbackPickup.pickupPreparationHours|default(4))
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
TEXT;

    private const NEW_PLAIN_EN = <<<'TEXT'
{% set orderEntity = order is defined ? order : null %}
{% set orderNumberResolved = orderNumber|default(orderEntity ? orderEntity.orderNumber|default('') : '') %}
{% set customer = customer|default(orderEntity ? orderEntity.orderCustomer|default({}) : {}) %}
{% set pickupDelivery = orderEntity ? orderEntity.deliveries|first : null %}
{% set pickupFields = pickupDelivery ? pickupDelivery.customFields|default({}) : {} %}
{% set fallbackPickup = (pickup is defined and pickup is iterable) ? pickup : config|default({}) %}
{% set pickup = {
        'storeName': pickupFields.foerde_click_collect_store_name|default(fallbackPickup.storeName|default('')),
        'storeAddress': pickupFields.foerde_click_collect_store_address|default(fallbackPickup.storeAddress|default('')),
        'openingHours': pickupFields.foerde_click_collect_opening_hours|default(fallbackPickup.openingHours|default('')),
        'pickupWindowDays': pickupFields.foerde_click_collect_pickup_window_days|default(fallbackPickup.pickupWindowDays|default(2)),
        'pickupPreparationHours': pickupFields.foerde_click_collect_pickup_preparation_hours|default(fallbackPickup.pickupPreparationHours|default(4))
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
TEXT;

    public function getCreationTimestamp(): int
    {
        return 1761266600;
    }

    public function update(Connection $connection): void
    {
        $typeId = $connection->fetchOne('SELECT id FROM mail_template_type WHERE technical_name = :name', ['name' => self::TYPE_NAME]);
        if (!$typeId) {
            return;
        }

        $templateIds = $connection->fetchFirstColumn('SELECT id FROM mail_template WHERE mail_template_type_id = :typeId', ['typeId' => $typeId]);
        if ($templateIds === false || $templateIds === []) {
            return;
        }

        $deLang = $this->getLanguageIdByLocaleCode($connection, 'de-DE');
        $enLang = $this->getLanguageIdByLocaleCode($connection, 'en-GB');

        foreach ($templateIds as $rawTemplateId) {
            $templateId = $this->toBytes($rawTemplateId);
            if ($templateId === null) {
                continue;
            }

            $translations = $connection->fetchAllAssociative(
                'SELECT language_id, subject, content_html, content_plain FROM mail_template_translation WHERE mail_template_id = :templateId',
                ['templateId' => $templateId]
            );

            foreach ($translations as $translation) {
                if (!isset($translation['language_id'])) {
                    continue;
                }

                $languageId = $translation['language_id'];
                $subject = (string) ($translation['subject'] ?? '');
                $html = (string) ($translation['content_html'] ?? '');
                $plain = (string) ($translation['content_plain'] ?? '');

                if (!$this->needsUpdate($subject, $html, $plain)) {
                    continue;
                }

                if ($deLang !== null && $languageId === $deLang) {
                    $this->writeTranslation($connection, $templateId, $languageId, self::NEW_SUBJECT_DE, self::NEW_HTML_DE, self::NEW_PLAIN_DE);
                    continue;
                }

                if ($enLang !== null && $languageId === $enLang) {
                    $this->writeTranslation($connection, $templateId, $languageId, self::NEW_SUBJECT_EN, self::NEW_HTML_EN, self::NEW_PLAIN_EN);
                    continue;
                }

                // Fallback: use EN template when locale not matched but guard is missing
                $this->writeTranslation($connection, $templateId, $languageId, self::NEW_SUBJECT_EN, self::NEW_HTML_EN, self::NEW_PLAIN_EN);
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // no-op
    }

    private function needsUpdate(string $subject, string $html, string $plain): bool
    {
        if ($subject === self::OLD_SUBJECT_DE || $subject === self::OLD_SUBJECT_EN) {
            return true;
        }

        if ($subject === self::NEW_SUBJECT_DE || $subject === self::NEW_SUBJECT_EN) {
            return false;
        }

        if (str_contains($html, self::OLD_GUARD_MARKER) || str_contains($plain, self::OLD_GUARD_MARKER)) {
            return true;
        }

        return false;
    }

    private function writeTranslation(Connection $connection, string $templateId, string $languageId, string $subject, string $html, string $plain): void
    {
        $connection->update(
            'mail_template_translation',
            [
                'subject' => $subject,
                'content_html' => $html,
                'content_plain' => $plain,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            [
                'mail_template_id' => $templateId,
                'language_id' => $languageId,
            ]
        );
    }

    private function toBytes(string $id): ?string
    {
        if ($id === '') {
            return null;
        }

        if (preg_match('/^[0-9a-f]{32}$/i', $id) === 1) {
            return Uuid::fromHexToBytes($id);
        }

        return $id;
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
