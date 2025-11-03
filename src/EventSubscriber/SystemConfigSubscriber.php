<?php declare(strict_types=1);

namespace FbClickCollect\EventSubscriber;

use Doctrine\DBAL\Connection;
use FbClickCollect\ScheduledTask\SendRemindersTask;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SystemConfigSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $scheduledTaskRepository,
        private readonly SystemConfigService $systemConfig,
        private readonly Connection $connection
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class => 'onSystemConfigChanged',
        ];
    }

    public function onSystemConfigChanged(SystemConfigChangedEvent $event): void
    {
        $keys = [];
        if (method_exists($event, 'getChangedKeys')) {
            $keys = $event->getChangedKeys();
        } elseif (method_exists($event, 'getKey')) {
            $keys = [$event->getKey()];
        }

        foreach ($keys as $key) {
            if ($key === 'FbClickCollect.config.reminderRunTime' || $key === 'core.basicInformation.timezone') {
                $this->alignNextExecution();
                return;
            }

            if ($key === 'FbClickCollect.config.storeEmail' || $key === 'FbClickCollect.config.storeName') {
                $this->updateStaffFlowRecipient();
            }
        }
    }

    private function alignNextExecution(): void
    {
        $context = Context::createDefaultContext();

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('name', SendRemindersTask::getTaskName()))
            ->setLimit(1);
        $task = $this->scheduledTaskRepository->search($criteria, $context)->first();
        if (!$task) {
            return;
        }

        $timeStr = (string) ($this->systemConfig->get('FbClickCollect.config.reminderRunTime') ?? '06:00');
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $timeStr, $m)) {
            $m = [null, '06', '00'];
        }
        $hour = min(23, max(0, (int) $m[1]));
        $minute = min(59, max(0, (int) $m[2]));

        $tzId = (string) ($this->systemConfig->get('core.basicInformation.timezone') ?? 'Europe/Berlin');
        try {
            $tz = new \DateTimeZone($tzId);
        } catch (\Throwable) {
            $tz = new \DateTimeZone('Europe/Berlin');
        }

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowLocal = $nowUtc->setTimezone($tz);
        $targetLocal = $nowLocal->setTime($hour, $minute, 0);
        if ($targetLocal <= $nowLocal) {
            $targetLocal = $targetLocal->modify('+1 day');
        }
        $targetUtc = $targetLocal->setTimezone(new \DateTimeZone('UTC'));

        $this->scheduledTaskRepository->update([
            [
                'id' => $task->getUniqueIdentifier(),
                'runInterval' => 86400,
                'nextExecutionTime' => $targetUtc,
            ],
        ], $context);
    }

    private function updateStaffFlowRecipient(): void
    {
        // Get flow ID for Click & Collect order confirmation
        $flowId = $this->connection->fetchOne(
            'SELECT id FROM flow WHERE name = :name LIMIT 1',
            ['name' => 'Click & Collect order confirmation']
        );

        if (!$flowId) {
            return;
        }

        // Get staff mail template info
        $row = $this->connection->fetchAssociative(
            'SELECT mt.id AS template_id, mtt.id AS type_id
             FROM mail_template mt
             INNER JOIN mail_template_type mtt ON mt.mail_template_type_id = mtt.id
             WHERE mtt.technical_name = :name
             LIMIT 1',
            ['name' => 'fb_click_collect.staff_order_placed']
        );

        if (!$row) {
            return;
        }

        $templateId = $row['template_id'];
        $typeId = $row['type_id'];

        // Get current store email and name from config
        $storeEmail = $this->getConfigString('FbClickCollect.config.storeEmail');
        $storeName = $this->getConfigString('FbClickCollect.config.storeName');

        // Apply fallbacks
        $staffRecipientEmail = is_string($storeEmail) && strpos($storeEmail, '@') !== false ? trim($storeEmail) : '';
        $staffRecipientName = is_string($storeName) && $storeName !== '' ? trim($storeName) : '';

        if ($staffRecipientEmail === '') {
            $adminEmail = $this->getConfigString('core.basicInformation.email');
            $staffRecipientEmail = is_string($adminEmail) && strpos($adminEmail, '@') !== false ? trim($adminEmail) : '';
        }

        if ($staffRecipientName === '') {
            $adminCompany = $this->getConfigString('core.basicInformation.shopName');
            $staffRecipientName = is_string($adminCompany) && $adminCompany !== '' ? trim($adminCompany) : ($staffRecipientEmail !== '' ? $staffRecipientEmail : 'Staff');
        }

        if ($staffRecipientEmail === '') {
            // No valid recipient, delete the staff sequence if it exists
            $this->connection->executeStatement(
                'DELETE FROM flow_sequence WHERE flow_id = :flowId AND action_name = :action AND position = 2',
                [
                    'flowId' => $flowId,
                    'action' => 'action.mail.send',
                ]
            );
            return;
        }

        // Build mail config
        $config = [
            'recipient' => [
                'type' => 'custom',
                'data' => [
                    $staffRecipientEmail => $staffRecipientName,
                ],
            ],
            'mailTemplateId' => Uuid::fromBytesToHex($templateId),
            'mailTemplateTypeId' => Uuid::fromBytesToHex($typeId),
        ];

        $configJson = json_encode($config, JSON_THROW_ON_ERROR);

        // Check if staff sequence exists
        $staffSequenceId = $this->connection->fetchOne(
            'SELECT id FROM flow_sequence WHERE flow_id = :flowId AND action_name = :action AND position = 2 AND true_case = 1',
            [
                'flowId' => $flowId,
                'action' => 'action.mail.send',
            ]
        );

        if ($staffSequenceId) {
            // Update existing sequence
            $this->connection->update('flow_sequence', [
                'config' => $configJson,
            ], [
                'id' => $staffSequenceId,
            ]);
        } else {
            // Create new staff sequence (in case it was deleted)
            $rootId = $this->connection->fetchOne(
                'SELECT id FROM flow_sequence WHERE flow_id = :flowId AND parent_id IS NULL LIMIT 1',
                ['flowId' => $flowId]
            );

            if ($rootId) {
                $this->connection->insert('flow_sequence', [
                    'id' => Uuid::randomBytes(),
                    'flow_id' => $flowId,
                    'parent_id' => $rootId,
                    'rule_id' => null,
                    'action_name' => 'action.mail.send',
                    'position' => 2,
                    'true_case' => 1,
                    'display_group' => 1,
                    'config' => $configJson,
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    private function getConfigString(string $key): ?string
    {
        $value = $this->connection->fetchOne(
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
}
