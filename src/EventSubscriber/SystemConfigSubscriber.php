<?php declare(strict_types=1);

namespace FbClickCollect\EventSubscriber;

use FbClickCollect\ScheduledTask\SendRemindersTask;
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
}
