<?php declare(strict_types=1);

namespace FbClickCollect\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SendRemindersCommand extends Command
{
    protected static $defaultName = 'fb:click-collect:send-reminders';
    protected static $defaultDescription = 'Send Click & Collect pickup reminders for ready deliveries within pickup window';

    public function __construct(private readonly \FbClickCollect\Service\ReminderService $reminderService)
    {
        parent::__construct(self::$defaultName);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $now = new \DateTimeImmutable('now');
        $today = $now->format('Y-m-d');

        // Require a DB template to exist; otherwise fail fast (validated by service)

        // Find deliveries in state 'ready' for click_collect, not yet picked/cancelled, and still within pickup window.
        // We approximate by comparing order.created_at + pickupWindowDays >= today.
        // Note: Without persistence of last sent timestamp, run this daily to avoid multiple sends per day.

        try {
            $sent = $this->reminderService->sendReminders($io);
            $io->success(sprintf('Reminder dispatch finished. Sent: %d', $sent));
            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
