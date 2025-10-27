<?php declare(strict_types=1);

namespace FoerdeClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1761265000PickupSnapshotFields extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        // 2025-10-26 12:00:00 UTC
        return 1761265000;
    }

    public function update(Connection $connection): void
    {
        $setName = 'foerde_click_collect_delivery';
        $setId = $this->getCustomFieldSetId($connection, $setName);
        if (!$setId) {
            $setId = Uuid::randomBytes();
            $connection->insert('custom_field_set', [
                'id' => $setId,
                'name' => $setName,
                'config' => json_encode([
                    'label' => [
                        'en-GB' => 'Click & Collect pickup info',
                        'de-DE' => 'Click & Collect Abholinformationen',
                    ],
                ], JSON_THROW_ON_ERROR),
                'active' => 1,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            $this->insertSetTranslation($connection, $setId, 'en-GB', 'Click & Collect pickup info');
            $this->insertSetTranslation($connection, $setId, 'de-DE', 'Click & Collect Abholinformationen');
        }

        $this->ensureSetRelation($connection, $setId, 'order_delivery');

        $this->ensureCustomField($connection, $setId, 'foerde_click_collect_store_name', 'text', [
            'label' => [
                'en-GB' => 'Store name',
                'de-DE' => 'Marktname',
            ],
        ]);
        $this->ensureCustomField($connection, $setId, 'foerde_click_collect_store_address', 'text', [
            'label' => [
                'en-GB' => 'Store address',
                'de-DE' => 'Marktadresse',
            ],
            'componentName' => 'sw-textarea-field',
        ]);
        $this->ensureCustomField($connection, $setId, 'foerde_click_collect_opening_hours', 'text', [
            'label' => [
                'en-GB' => 'Opening hours',
                'de-DE' => 'Oeffnungszeiten',
            ],
            'componentName' => 'sw-textarea-field',
        ]);
        $this->ensureCustomField($connection, $setId, 'foerde_click_collect_pickup_window_days', 'int', [
            'label' => [
                'en-GB' => 'Pickup window (days)',
                'de-DE' => 'Abholfenster (Tage)',
            ],
            'numberType' => 'int',
        ]);
        $this->ensureCustomField($connection, $setId, 'foerde_click_collect_pickup_preparation_hours', 'int', [
            'label' => [
                'en-GB' => 'Preparation time (hours)',
                'de-DE' => 'Vorbereitung (Stunden)',
            ],
            'numberType' => 'int',
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
        // no destructive changes
    }

    private function getCustomFieldSetId(Connection $connection, string $name): ?string
    {
        $id = $connection->fetchOne('SELECT id FROM custom_field_set WHERE name = :name LIMIT 1', ['name' => $name]);
        if (!$id) {
            return null;
        }

        if (is_string($id) && strlen($id) === 32 && preg_match('/^[0-9a-f]{32}$/i', $id)) {
            return Uuid::fromHexToBytes($id);
        }

        return is_string($id) ? $id : null;
    }

    private function ensureSetRelation(Connection $connection, string $setId, string $entity): void
    {
        $exists = $connection->fetchOne('SELECT 1 FROM custom_field_set_relation WHERE custom_field_set_id = :id AND entity_name = :entity', [
            'id' => $setId,
            'entity' => $entity,
        ]);

        if ($exists) {
            return;
        }

        $connection->insert('custom_field_set_relation', [
            'custom_field_set_id' => $setId,
            'entity_name' => $entity,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function ensureCustomField(Connection $connection, string $setId, string $name, string $type, array $config): void
    {
        $fieldId = $connection->fetchOne('SELECT id FROM custom_field WHERE name = :name LIMIT 1', ['name' => $name]);
        if ($fieldId) {
            $connection->update('custom_field', [
                'config' => json_encode($config, JSON_THROW_ON_ERROR),
            ], ['name' => $name]);

            return;
        }

        $connection->insert('custom_field', [
            'id' => Uuid::randomBytes(),
            'name' => $name,
            'type' => $type,
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
            'active' => 1,
            'custom_field_set_id' => $setId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function insertSetTranslation(Connection $connection, string $setId, string $localeCode, string $label): void
    {
        $languageId = $this->getLanguageIdByLocale($connection, $localeCode);
        if (!$languageId) {
            return;
        }

        $exists = $connection->fetchOne('SELECT 1 FROM custom_field_set_translation WHERE custom_field_set_id = :id AND language_id = :language', [
            'id' => $setId,
            'language' => $languageId,
        ]);
        if ($exists) {
            $connection->update('custom_field_set_translation', [
                'label' => $label,
            ], [
                'custom_field_set_id' => $setId,
                'language_id' => $languageId,
            ]);

            return;
        }

        $connection->insert('custom_field_set_translation', [
            'custom_field_set_id' => $setId,
            'language_id' => $languageId,
            'label' => $label,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function getLanguageIdByLocale(Connection $connection, string $localeCode): ?string
    {
        $id = $connection->fetchOne(
            'SELECT language.id FROM language INNER JOIN locale ON locale.id = language.locale_id WHERE locale.code = :code LIMIT 1',
            ['code' => $localeCode]
        );

        if (!$id) {
            return null;
        }

        if (is_string($id) && strlen($id) === 32 && preg_match('/^[0-9a-f]{32}$/i', $id)) {
            return Uuid::fromHexToBytes($id);
        }

        return is_string($id) ? $id : null;
    }
}
