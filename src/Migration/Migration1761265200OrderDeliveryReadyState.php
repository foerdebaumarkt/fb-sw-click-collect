<?php declare(strict_types=1);

namespace FoerdeClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Ensures the Click & Collect specific ready state exists; core Shopware only seeds
 * the default delivery states, so this migration keeps reminder flows working on fresh installs.
 */
class Migration1761265200OrderDeliveryReadyState extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1761265200;
    }

    public function update(Connection $connection): void
    {
        $stateMachineId = $this->getStateMachineId($connection, 'order_delivery.state');
        if ($stateMachineId === null) {
            return;
        }

        $readyStateId = $this->getStateId($connection, $stateMachineId, 'ready');
        if ($readyStateId === null) {
            $readyStateId = Uuid::randomBytes();
            $connection->insert('state_machine_state', [
                'id' => $readyStateId,
                'technical_name' => 'ready',
                'state_machine_id' => $stateMachineId,
                'created_at' => $this->now(),
            ]);

            $this->ensureStateTranslation($connection, $readyStateId, 'en-GB', 'Ready for pickup');
            $this->ensureStateTranslation($connection, $readyStateId, 'de-DE', 'Abholbereit');
        }

        $openId = $this->getStateId($connection, $stateMachineId, 'open');
        if ($openId !== null) {
            $this->ensureTransition($connection, 'mark_ready', $stateMachineId, $openId, $readyStateId);
            $this->ensureTransition($connection, 'reopen', $stateMachineId, $readyStateId, $openId);
        }

        $cancelledId = $this->getStateId($connection, $stateMachineId, 'cancelled');
        if ($cancelledId !== null) {
            $this->ensureTransition($connection, 'cancel', $stateMachineId, $readyStateId, $cancelledId);
        }

        $shippedId = $this->getStateId($connection, $stateMachineId, 'shipped');
        if ($shippedId !== null) {
            $this->ensureTransition($connection, 'ship', $stateMachineId, $readyStateId, $shippedId);
        }

        $shippedPartiallyId = $this->getStateId($connection, $stateMachineId, 'shipped_partially');
        if ($shippedPartiallyId !== null) {
            $this->ensureTransition($connection, 'ship_partially', $stateMachineId, $readyStateId, $shippedPartiallyId);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function ensureTransition(Connection $connection, string $action, string $stateMachineId, string $fromId, string $toId): void
    {
        $exists = $connection->fetchOne(
            'SELECT id FROM state_machine_transition WHERE state_machine_id = :smid AND action_name = :action AND from_state_id = :fromId AND to_state_id = :toId LIMIT 1',
            [
                'smid' => $stateMachineId,
                'action' => $action,
                'fromId' => $fromId,
                'toId' => $toId,
            ]
        );

        if ($exists) {
            return;
        }

        $connection->insert('state_machine_transition', [
            'id' => Uuid::randomBytes(),
            'action_name' => $action,
            'state_machine_id' => $stateMachineId,
            'from_state_id' => $fromId,
            'to_state_id' => $toId,
            'created_at' => $this->now(),
        ]);
    }

    private function ensureStateTranslation(Connection $connection, string $stateId, string $localeCode, string $label): void
    {
        if (!$this->tableExists($connection, 'state_machine_state_translation')) {
            return;
        }

        $languageId = $this->getLanguageIdByLocale($connection, $localeCode);
        if ($languageId === null) {
            return;
        }

        $exists = $connection->fetchOne(
            'SELECT 1 FROM state_machine_state_translation WHERE state_machine_state_id = :stateId AND language_id = :languageId',
            [
                'stateId' => $stateId,
                'languageId' => $languageId,
            ]
        );

        if ($exists) {
            $connection->update('state_machine_state_translation', [
                'name' => $label,
                'updated_at' => $this->now(),
            ], [
                'state_machine_state_id' => $stateId,
                'language_id' => $languageId,
            ]);

            return;
        }

        $connection->insert('state_machine_state_translation', [
            'state_machine_state_id' => $stateId,
            'language_id' => $languageId,
            'name' => $label,
            'created_at' => $this->now(),
        ]);
    }

    private function getStateMachineId(Connection $connection, string $technicalName): ?string
    {
        $id = $connection->fetchOne(
            'SELECT id FROM state_machine WHERE technical_name = :name LIMIT 1',
            ['name' => $technicalName]
        );

        return $this->normalizeId($id);
    }

    private function getStateId(Connection $connection, string $stateMachineId, string $technicalName): ?string
    {
        $id = $connection->fetchOne(
            'SELECT id FROM state_machine_state WHERE state_machine_id = :smid AND technical_name = :name LIMIT 1',
            [
                'smid' => $stateMachineId,
                'name' => $technicalName,
            ]
        );

        return $this->normalizeId($id);
    }

    private function getLanguageIdByLocale(Connection $connection, string $localeCode): ?string
    {
        $id = $connection->fetchOne(
            'SELECT language.id FROM language INNER JOIN locale ON locale.id = language.locale_id WHERE locale.code = :code LIMIT 1',
            ['code' => $localeCode]
        );

        return $this->normalizeId($id);
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        $schema = $connection->fetchOne('SELECT DATABASE()');
        if (!is_string($schema) || $schema === '') {
            return false;
        }

        $exists = $connection->fetchOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table LIMIT 1',
            [
                'schema' => $schema,
                'table' => $table,
            ]
        );

        return (bool) $exists;
    }

    private function normalizeId(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        if (strlen($value) === 16) {
            return $value;
        }

        if (preg_match('/^[0-9a-f]{32}$/i', $value)) {
            return Uuid::fromHexToBytes($value);
        }

        return null;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
