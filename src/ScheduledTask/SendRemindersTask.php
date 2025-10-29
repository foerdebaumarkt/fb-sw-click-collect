<?php declare(strict_types=1);

namespace FbClickCollect\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class SendRemindersTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'fb_click_collect.send_reminders';
    }

    public static function getDefaultInterval(): int
    {
        // Run once per day
        return 86400;
    }
}
