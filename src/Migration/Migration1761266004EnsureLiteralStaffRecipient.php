<?php declare(strict_types=1);

namespace FbClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1761266004EnsureLiteralStaffRecipient extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        // 2025-11-01 00:00:04 UTC
        return 1761266004;
    }

    public function update(Connection $connection): void
    {
        $flowId = $this->hexToBytes('cad8d95db611406a96332835a2affb3c');
        $sequenceId = $this->hexToBytes('7377b721908f4a76a56e476629f9d198');

        $storeEmail = $this->getConfigString($connection, 'FbClickCollect.config.storeEmail');
        if ($storeEmail === null || $storeEmail === '') {
            return;
        }

        $storeName = $this->getConfigString($connection, 'FbClickCollect.config.storeName');

        $configJson = $connection->fetchOne(
            'SELECT config FROM flow_sequence WHERE id = :id AND flow_id = :flowId',
            [
                'id' => $sequenceId,
                'flowId' => $flowId,
            ]
        );

        if (!\is_string($configJson) || $configJson === '') {
            return;
        }

        try {
            $config = json_decode($configJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return;
        }

        if (!\is_array($config)) {
            return;
        }

        $recipient = $config['recipient'] ?? null;
        if (!\is_array($recipient) || ($recipient['type'] ?? null) !== 'custom') {
            return;
        }

        $recipientData = $recipient['data'] ?? [];
        if (!\is_array($recipientData)) {
            $recipientData = [];
        }

        $normalized = $this->normalizeRecipientData($recipientData);

        $resolvedName = $storeName !== null && $storeName !== ''
            ? $storeName
            : $this->extractRecipientName($normalized);

        if ($resolvedName === null || $resolvedName === '') {
            $resolvedName = 'Shop Team';
        }

        $target = [$storeEmail => $resolvedName];

        if ($normalized === $target && !$this->isList($recipientData)) {
            return;
        }

        $config['recipient']['data'] = $target;
        $connection->update('flow_sequence', [
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'id' => $sequenceId,
            'flow_id' => $flowId,
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
        // noop
    }

    private function hexToBytes(string $value): string
    {
        if (strlen($value) === 16) {
            return $value;
        }

        if (preg_match('/^[0-9a-f]{32}$/i', $value) === 1) {
            return Uuid::fromHexToBytes($value);
        }

        throw new \RuntimeException('Invalid UUID value supplied.');
    }

    private function normalizeRecipientData(array $data): array
    {
        if (!$this->isList($data)) {
            return $data;
        }

        $normalized = [];
        foreach ($data as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $type = $entry['type'] ?? null;
            $email = $entry['value'] ?? null;
            $name = $entry['name'] ?? '';

            if ($type !== 'email' || !\is_string($email)) {
                continue;
            }

            $email = trim($email);
            if ($email === '') {
                continue;
            }

            if (!\is_string($name)) {
                $name = '';
            }

            $normalized[$email] = trim($name);
        }

        return $normalized;
    }

    private function extractRecipientName(array $data): ?string
    {
        if ($data === []) {
            return null;
        }

        if ($this->isList($data)) {
            foreach ($data as $entry) {
                if (!\is_array($entry)) {
                    continue;
                }

                $name = $entry['name'] ?? null;
                if (\is_string($name)) {
                    $name = trim($name);
                    if ($name !== '') {
                        return $name;
                    }
                }
            }

            return null;
        }

        $first = reset($data);
        if (\is_string($first)) {
            $first = trim($first);
            if ($first !== '') {
                return $first;
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

    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
