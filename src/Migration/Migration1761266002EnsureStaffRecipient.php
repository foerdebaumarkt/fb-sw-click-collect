<?php declare(strict_types=1);

namespace FoerdeClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1761266002EnsureStaffRecipient extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        // 2025-11-01 00:00:02 UTC
        return 1761266002;
    }

    public function update(Connection $connection): void
    {
        $flowId = $this->hexToBytes('cad8d95db611406a96332835a2affb3c');
        $sequenceId = $this->hexToBytes('7377b721908f4a76a56e476629f9d198');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $flowExists = (bool) $connection->fetchOne('SELECT 1 FROM flow WHERE id = :id', ['id' => $flowId]);
        if (!$flowExists) {
            return;
        }

        $staffTemplate = $this->getTemplateAndType($connection, 'fb_click_collect.staff_order_placed');
        if ($staffTemplate === null) {
            return;
        }

        $config = $this->buildMailConfigJson(
            $staffTemplate,
            'custom',
            [[
                'type' => 'email',
                'value' => '{{ (order.deliveries|first ? ((order.deliveries|first).customFields.foerde_click_collect_store_email|default(null)) : null)
                    ?: config("FoerdeClickCollect.config.storeEmail", salesChannel.id)
                    ?: config("FoerdeClickCollect.config.storeEmail")
                    ?: config("core.basicInformation.email", salesChannel.id)
                    ?: config("core.basicInformation.email")
                    ?: config("core.mailerSettings.senderAddress", salesChannel.id)
                    ?: config("core.mailerSettings.senderAddress") }}',
                'name' => '{{ (order.deliveries|first ? ((order.deliveries|first).customFields.foerde_click_collect_store_name|default(null)) : null)
                    ?? config("FoerdeClickCollect.config.storeName", salesChannel.id)
                    ?? config("core.basicInformation.company", salesChannel.id)
                    ?? (salesChannel.translated.name ?? "Shop Team") }}',
            ]]
        );

        $connection->update('flow_sequence', [
            'config' => $config,
            'updated_at' => $now,
        ], [
            'id' => $sequenceId,
            'flow_id' => $flowId,
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
        // noop
    }

    /**
     * @param array{templateId:string,typeId:string} $template
     * @param array<int,array<string,string>>|null $customRecipients
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
}
