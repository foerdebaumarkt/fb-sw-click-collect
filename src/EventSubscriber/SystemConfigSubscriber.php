<?php declare(strict_types=1);

namespace FoerdeClickCollect\EventSubscriber;

use FoerdeClickCollect\ScheduledTask\SendRemindersTask;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SystemConfigSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $scheduledTaskRepository,
        private readonly SystemConfigService $systemConfig
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class => 'onSystemConfigChanged',
        ];
    }

    public function onSystemConfigChanged(SystemConfigChangedEvent $event): void
    {
        $changed = $event->getChangedKeys();
        $needsAlign = false;
        foreach ($changed as $key) {
            if ($key === 'FoerdeClickCollect.config.reminderRunTime' || $key === 'core.basicInformation.timezone') {
                $needsAlign = true;
                break;
            }
        }
        if (!$needsAlign) {
            return;
        }

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
