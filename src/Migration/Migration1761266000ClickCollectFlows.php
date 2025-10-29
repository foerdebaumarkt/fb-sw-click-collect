<?php declare(strict_types=1);

namespace FoerdeClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1761266000ClickCollectFlows extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        // 2025-11-01 00:00:00 UTC
        return 1761266000;
    }

    public function update(Connection $connection): void
    {
        $this->deactivateDefaultOrderPlacedFlow($connection);

        $this->upsertOrderConfirmationFlow($connection);
        $this->upsertReadyForPickupFlow($connection);
        $this->registerPickupReminderFlowTemplate($connection);
        $this->upsertPickupReminderFlow($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
        // noop
    }

    private function deactivateDefaultOrderPlacedFlow(Connection $connection): void
    {
        $connection->executeStatement(
            'UPDATE `flow`
             SET active = 0
             WHERE event_name = :eventName
               AND name = :name',
            [
                'eventName' => 'checkout.order.placed',
                'name' => 'Order placed',
            ]
        );
    }

    private function upsertOrderConfirmationFlow(Connection $connection): void
    {
        $flowId = $this->hexToBytes('cad8d95db611406a96332835a2affb3c');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $ruleId = $this->getRuleId($connection, 'Click & Collect: only with pickup shipping');
        if ($ruleId === null) {
            return;
        }

        $customerTemplate = $this->getTemplateAndType($connection, 'fb_click_collect.order_confirmation');
        $staffTemplate = $this->getTemplateAndType($connection, 'fb_click_collect.staff_order_placed');
        $defaultTemplate = $this->getTemplateAndType($connection, 'order_confirmation_mail');

        if ($customerTemplate === null || $staffTemplate === null || $defaultTemplate === null) {
            return;
        }

        $this->upsertFlow(
            $connection,
            $flowId,
            [
                'name' => 'Foerde Click & Collect order confirmation',
                'event_name' => 'checkout.order.placed',
                'active' => 1,
                'priority' => 1,
                'description' => 'Provisioned by Foerde Click & Collect plugin',
                'payload' => null,
            ],
            $now
        );

        $connection->executeStatement('DELETE FROM flow_sequence WHERE flow_id = :id', ['id' => $flowId]);

        $rootId = $this->hexToBytes('e421bc50b531417b86e5bdb3c7e5de7b');
        $customerSequenceId = $this->hexToBytes('da781967c55c478c9ece1ce47d7c02e2');
        $staffSequenceId = $this->hexToBytes('7377b721908f4a76a56e476629f9d198');
        $fallbackSequenceId = $this->hexToBytes('6c339bdb4259425c883c9224a66cf2b1');

        $this->insertSequence($connection, [
            'id' => $rootId,
            'flow_id' => $flowId,
            'parent_id' => null,
            'rule_id' => $ruleId,
            'action_name' => null,
            'position' => 1,
            'true_case' => 0,
            'display_group' => 1,
            'config' => json_encode(new \stdClass(), JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);

        $this->insertSequence($connection, [
            'id' => $customerSequenceId,
            'flow_id' => $flowId,
            'parent_id' => $rootId,
            'rule_id' => null,
            'action_name' => 'action.mail.send',
            'position' => 1,
            'true_case' => 1,
            'display_group' => 1,
            'config' => $this->buildMailConfigJson($customerTemplate),
            'created_at' => $now,
        ]);

        $storeEmail = $this->getConfigString($connection, 'FoerdeClickCollect.config.storeEmail');
        $storeName = $this->getConfigString($connection, 'FoerdeClickCollect.config.storeName');

        $staffRecipientEmailExpression = '{{ (order.deliveries|first ? ((order.deliveries|first).customFields.foerde_click_collect_store_email|default(null)) : null)
            ?: config("FoerdeClickCollect.config.storeEmail", salesChannel.id)
            ?: config("FoerdeClickCollect.config.storeEmail")
            ?: config("core.basicInformation.email", salesChannel.id)
            ?: config("core.basicInformation.email")
            ?: config("core.mailerSettings.senderAddress", salesChannel.id)
            ?: config("core.mailerSettings.senderAddress") }}';

        $staffRecipientNameExpression = '{{ (order.deliveries|first ? ((order.deliveries|first).customFields.foerde_click_collect_store_name|default(null)) : null)
            ?? config("FoerdeClickCollect.config.storeName", salesChannel.id)
            ?? config("core.basicInformation.company", salesChannel.id)
            ?? (salesChannel.translated.name ?? "Shop Team") }}';

        $staffRecipientEmail = $storeEmail !== null && $storeEmail !== '' ? $storeEmail : $staffRecipientEmailExpression;
        $staffRecipientName = $storeName !== null && $storeName !== '' ? $storeName : $staffRecipientNameExpression;

        $this->insertSequence($connection, [
            'id' => $staffSequenceId,
            'flow_id' => $flowId,
            'parent_id' => $rootId,
            'rule_id' => null,
            'action_name' => 'action.mail.send',
            'position' => 2,
            'true_case' => 1,
            'display_group' => 1,
            'config' => $this->buildMailConfigJson(
                $staffTemplate,
                'custom',
                [
                    $staffRecipientEmail => $staffRecipientName,
                ]
            ),
            'created_at' => $now,
        ]);

        $this->insertSequence($connection, [
            'id' => $fallbackSequenceId,
            'flow_id' => $flowId,
            'parent_id' => $rootId,
            'rule_id' => null,
            'action_name' => 'action.mail.send',
            'position' => 1,
            'true_case' => 0,
            'display_group' => 1,
            'config' => $this->buildMailConfigJson($defaultTemplate),
            'created_at' => $now,
        ]);
    }

    private function upsertReadyForPickupFlow(Connection $connection): void
    {
        $flowId = $this->hexToBytes('4503d09c5ef34a95a44cfbf7ad41ac10');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $template = $this->getTemplateAndType($connection, 'fb_click_collect.ready');
        if ($template === null) {
            return;
        }

        $this->upsertFlow(
            $connection,
            $flowId,
            [
                'name' => 'Foerde Click & Collect ready for pickup',
                'event_name' => 'state_enter.order_delivery.state.ready',
                'active' => 1,
                'priority' => 1,
                'description' => 'Provisioned by Foerde Click & Collect plugin',
                'payload' => null,
            ],
            $now
        );

        $connection->executeStatement('DELETE FROM flow_sequence WHERE flow_id = :id', ['id' => $flowId]);

        $sequenceId = $this->hexToBytes('68c85640a8ae463ab5d258a576f3030b');

        $this->insertSequence($connection, [
            'id' => $sequenceId,
            'flow_id' => $flowId,
            'parent_id' => null,
            'rule_id' => null,
            'action_name' => 'action.mail.send',
            'position' => 1,
            'true_case' => 0,
            'display_group' => 1,
            'config' => $this->buildMailConfigJson($template),
            'created_at' => $now,
        ]);
    }

    private function upsertPickupReminderFlow(Connection $connection): void
    {
        $flowId = $this->hexToBytes('798a855bc93c48a7976d5c6406f3e73d');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $template = $this->getTemplateAndType($connection, 'fb_click_collect.reminder');
        if ($template === null) {
            return;
        }

        $this->upsertFlow(
            $connection,
            $flowId,
            [
                'name' => 'Foerde Click & Collect pickup reminder',
                'event_name' => 'foerde.click_collect.pickup_reminder',
                'active' => 1,
                'priority' => 1,
                'description' => 'Provisioned by Foerde Click & Collect plugin',
                'payload' => null,
            ],
            $now
        );

        $connection->executeStatement('DELETE FROM flow_sequence WHERE flow_id = :id', ['id' => $flowId]);

        $sequenceId = $this->hexToBytes('2ea0650286964aa9ab9c32f8146d53e5');

        $this->insertSequence($connection, [
            'id' => $sequenceId,
            'flow_id' => $flowId,
            'parent_id' => null,
            'rule_id' => null,
            'action_name' => 'action.mail.send',
            'position' => 1,
            'true_case' => 0,
            'display_group' => 1,
            'config' => $this->buildMailConfigJson($template),
            'created_at' => $now,
        ]);
    }

    private function registerPickupReminderFlowTemplate(Connection $connection): void
    {
        $config = json_encode([
            'name' => 'Foerde Click & Collect pickup reminder',
            'eventName' => 'foerde.click_collect.pickup_reminder',
            'description' => 'Triggered by Foerde Click & Collect reminder scheduler.',
            'sequences' => [],
            'customFields' => null,
        ], JSON_THROW_ON_ERROR);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $existingId = $connection->fetchOne(
            'SELECT id FROM flow_template WHERE JSON_EXTRACT(config, "$.eventName") = :eventName LIMIT 1',
            ['eventName' => 'foerde.click_collect.pickup_reminder']
        );

        if (\is_string($existingId) && $existingId !== '') {
            $connection->update('flow_template', ['config' => $config, 'updated_at' => $now], ['id' => $existingId]);

            return;
        }

        $connection->insert('flow_template', [
            'id' => $this->hexToBytes('4d9e2c68d3be4a0eb8582fb6f3bd3c66'),
            'config' => $config,
            'created_at' => $now,
        ]);
    }

    /**
     * @param array{templateId:string,typeId:string} $template
     * @param array<string,string>|null $customRecipients
     */
    private function buildMailConfigJson(array $template, string $recipientType = 'default', ?array $customRecipients = null): string
    {
        $config = [
            'recipient' => [
                'type' => $recipientType,
                'data' => $customRecipients ?? [],
            ],
            'mailTemplateId' => Uuid::fromBytesToHex($template['templateId']),
            'mailTemplateTypeId' => Uuid::fromBytesToHex($template['typeId']),
        ];

        return json_encode($config, JSON_THROW_ON_ERROR);
    }

    private function upsertFlow(Connection $connection, string $flowId, array $payload, string $now): void
    {
        $exists = (bool) $connection->fetchOne('SELECT 1 FROM flow WHERE id = :id', ['id' => $flowId]);

        if ($exists) {
            $payload['updated_at'] = $now;
            $connection->update('flow', $payload, ['id' => $flowId]);

            return;
        }

        $payload['id'] = $flowId;
        $payload['created_at'] = $now;

        $connection->insert('flow', $payload);
    }

    private function insertSequence(Connection $connection, array $payload): void
    {
        $connection->insert('flow_sequence', $payload);
    }

    private function getRuleId(Connection $connection, string $name): ?string
    {
        $id = $connection->fetchOne('SELECT id FROM `rule` WHERE name = :name ORDER BY created_at DESC LIMIT 1', ['name' => $name]);

        if (!\is_string($id) || $id === '') {
            return null;
        }

        return $this->hexToBytes($id);
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

    /**
     * @return array{templateId:string,typeId:string}|null
     */
    private function getTemplateAndType(Connection $connection, string $technicalName): ?array
    {
        $row = $connection->fetchAssociative(
            'SELECT mt.id AS template_id, mtt.id AS type_id
             FROM mail_template mt
             INNER JOIN mail_template_type mtt ON mt.mail_template_type_id = mtt.id
             WHERE mtt.technical_name = :name
             ORDER BY mt.updated_at DESC, mt.created_at DESC
             LIMIT 1',
            ['name' => $technicalName]
        );

        if (!$row) {
            return null;
        }

        $templateId = $row['template_id'] ?? null;
        $typeId = $row['type_id'] ?? null;

        if (!\is_string($templateId) || !\is_string($typeId)) {
            return null;
        }

        return [
            'templateId' => $this->hexToBytes($templateId),
            'typeId' => $this->hexToBytes($typeId),
        ];
    }

}
