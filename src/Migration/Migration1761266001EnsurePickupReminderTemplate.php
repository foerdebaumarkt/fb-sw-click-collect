<?php declare(strict_types=1);

namespace FbClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1761266001EnsurePickupReminderTemplate extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        // 2025-11-01 00:00:01 UTC
        return 1761266001;
    }

    public function update(Connection $connection): void
    {
        $config = json_encode([
            'name' => 'Click & Collect pickup reminder',
            'eventName' => 'fb.click_collect.pickup_reminder',
            'description' => 'Triggered by Click & Collect reminder scheduler.',
            'sequences' => [],
            'customFields' => null,
        ], JSON_THROW_ON_ERROR);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $existingId = $connection->fetchOne(
            'SELECT id FROM flow_template WHERE JSON_EXTRACT(config, "$.eventName") = :eventName LIMIT 1',
            ['eventName' => 'fb.click_collect.pickup_reminder']
        );

        $name = 'Click & Collect pickup reminder';

        if (\is_string($existingId) && $existingId !== '') {
            $connection->update('flow_template', ['name' => $name, 'config' => $config, 'updated_at' => $now], ['id' => $existingId]);

            return;
        }

        $connection->insert('flow_template', [
            'id' => hex2bin('4d9e2c68d3be4a0eb8582fb6f3bd3c66'),
            'name' => $name,
            'config' => $config,
            'created_at' => $now,
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
        // noop
    }
}
