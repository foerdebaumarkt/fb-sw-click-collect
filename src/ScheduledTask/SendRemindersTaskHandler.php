<?php declare(strict_types=1);

namespace FoerdeClickCollect\ScheduledTask;

use FoerdeClickCollect\Service\ReminderService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class SendRemindersTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly ReminderService $reminderService,
        private readonly SystemConfigService $systemConfig
    ) {
        parent::__construct($scheduledTaskRepository);
    }

    public static function getHandledMessages(): iterable
    {
        return [SendRemindersTask::class];
    }

    public function run(): void
    {
        // Run without console IO; exceptions are allowed to bubble to let the scheduler log failures
        $this->reminderService->sendReminders(null);
        // Align next execution to configured time-of-day
        $this->alignNextExecution();
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

        $timeStr = (string) ($this->systemConfig->get('FoerdeClickCollect.config.reminderRunTime') ?? '06:00');
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $timeStr, $m)) {
            $timeStr = '06:00';
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
}
