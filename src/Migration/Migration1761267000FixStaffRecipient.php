<?php declare(strict_types=1);

namespace FbClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1761267000FixStaffRecipient extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        // 2025-11-02 00:00:00 UTC
        return 1761267000;
    }

    public function update(Connection $connection): void
    {
        // Fixed sequence id for the staff email in the order confirmation flow, as provisioned earlier
        $sequenceId = $this->hexToBytes('7377b721908f4a76a56e476629f9d198');

        $row = $connection->fetchAssociative('SELECT config FROM flow_sequence WHERE id = :id', ['id' => $sequenceId]);
        if (!$row) {
            return;
        }

        $configJson = $row['config'] ?? null;
        if (!\is_string($configJson) || $configJson === '') {
            return;
        }

        try {
            $config = json_decode($configJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (!\is_array($config)) {
            return;
        }

        // Resolve strictly from plugin config; do not fall back to core values.
        $email = $this->getConfigString($connection, 'FbClickCollect.config.storeEmail');
        $name = $this->getConfigString($connection, 'FbClickCollect.config.storeName');

        if ($email === null || $email === '' || !str_contains($email, '@') || $name === null || trim($name) === '') {
            // Do not write an invalid recipient
            return;
        }

        $config['recipient'] = [
            'type' => 'custom',
            'data' => [
                trim($email) => trim((string) $name),
            ],
        ];

        try {
            $newJson = json_encode($config, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        $connection->update('flow_sequence', [
            'config' => $newJson,
        ], [
            'id' => $sequenceId,
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
        // noop
    }

    private function firstNonEmpty(string|array|null ...$values): ?string
    {
        foreach ($values as $val) {
            if (\is_string($val)) {
                $trim = trim($val);
                if ($trim !== '') {
                    return $trim;
                }
            }
        }

        return null;
    }

    private function getConfigString(Connection $connection, string $key): ?string
    {
        $value = $connection->fetchOne(
            'SELECT configuration_value FROM system_config WHERE configuration_key = :key AND sales_channel_id IS NULL ORDER BY created_at DESC LIMIT 1',
            ['key' => $key]
        );

        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (\is_string($decoded)) {
            $decoded = trim($decoded);

            return $decoded === '' ? null : $decoded;
        }

        if (\is_array($decoded)) {
            $candidates = [];
            if (\array_key_exists('_value', $decoded)) {
                $candidates[] = $decoded['_value'];
            }

            foreach ($decoded as $candidate) {
                $candidates[] = $candidate;
            }

            foreach ($candidates as $candidate) {
                if (\is_string($candidate)) {
                    $candidate = trim($candidate);
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    private function hexToBytes(string $value): string
    {
        if (\strlen($value) === 16) {
            return $value;
        }

        if (\preg_match('/^[0-9a-f]{32}$/i', $value) === 1) {
            return \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($value);
        }

        throw new \RuntimeException('Invalid UUID value supplied.');
    }
}
