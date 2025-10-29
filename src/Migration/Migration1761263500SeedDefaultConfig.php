<?php declare(strict_types=1);

namespace FbClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Seed default plugin configuration for fresh installs only.
 * This writes global (no sales channel) values when missing and never overrides existing values.
 */
class Migration1761263500SeedDefaultConfig extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        // 2025-10-29 00:00:00 UTC
        return 1761263500;
    }

    public function update(Connection $connection): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Only set when missing globally (sales_channel_id IS NULL)
        $this->ensureConfigDefault($connection, 'FbClickCollect.config.storeEmail', 'dev+store@local.test', $now);
        $this->ensureConfigDefault($connection, 'FbClickCollect.config.storeName', 'Ihr Markt', $now);
    }

    public function updateDestructive(Connection $connection): void
    {
        // noop
    }

    private function ensureConfigDefault(Connection $connection, string $key, string $value, string $now): void
    {
        $exists = (bool) $connection->fetchOne(
            'SELECT 1 FROM system_config WHERE configuration_key = :key AND sales_channel_id IS NULL LIMIT 1',
            ['key' => $key]
        );

        if ($exists) {
            return; // do not override existing installs/updates
        }

        $connection->insert('system_config', [
            'id' => Uuid::randomBytes(),
            'configuration_key' => $key,
            'configuration_value' => json_encode(['_value' => $value], JSON_THROW_ON_ERROR),
            'sales_channel_id' => null,
            'created_at' => $now,
        ]);
    }
}
