<?php declare(strict_types=1);

namespace FoerdeClickCollect\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Mail\Service\MailService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fb:click-collect:send-reminders', description: 'Send Click & Collect pickup reminders for ready deliveries within pickup window')]
class SendRemindersCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MailService $mailService,
        private readonly SystemConfigService $systemConfig,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $now = new \DateTimeImmutable('now');
        $today = $now->format('Y-m-d');

        // Require a DB template to exist; otherwise fail fast
        $typeId = $this->connection->fetchOne(
            'SELECT id FROM mail_template_type WHERE technical_name = :name',
            ['name' => 'fb_click_collect.reminder']
        );
        if (!$typeId) {
            $io->error('Missing mail_template_type fb_click_collect.reminder. Run migrations or create the template type.');
            return Command::FAILURE;
        }

        $templateId = $this->connection->fetchOne(
            'SELECT id FROM mail_template WHERE mail_template_type_id = :typeId ORDER BY created_at DESC LIMIT 1',
            ['typeId' => $typeId]
        );
        if (!$templateId) {
            $io->error('No mail_template found for type fb_click_collect.reminder. Create a template in Admin.');
            return Command::FAILURE;
        }

        // Find deliveries in state 'ready' for click_collect, not yet picked/cancelled, and still within pickup window.
        // We approximate by comparing order.created_at + pickupWindowDays >= today.
        // Note: Without persistence of last sent timestamp, run this daily to avoid multiple sends per day.

        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                o.order_number,
                o.sales_channel_id,
                o.language_id,
                o.created_at AS order_created,
                oc.email,
                CONCAT_WS(" ", oc.first_name, oc.last_name) AS customer_name,
                sm.technical_name AS shipping_tech
             FROM order_delivery od
             INNER JOIN `order` o ON o.id = od.order_id AND o.version_id = od.order_version_id
             INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.version_id = o.version_id
             INNER JOIN shipping_method sm ON sm.id = od.shipping_method_id
             INNER JOIN state_machine_state sms ON sms.id = od.state_id
             WHERE sm.technical_name = :tech
               AND sms.technical_name = :stateReady',
            [
                'tech' => 'click_collect',
                'stateReady' => 'ready',
            ]
        );

    $sent = 0;
        foreach ($rows as $row) {
            $salesChannelId = $row['sales_channel_id'];
            $languageId = $row['language_id'] ?? null;
            $orderCreated = $row['order_created'] ? new \DateTimeImmutable((string) $row['order_created']) : null;
            $email = (string) $row['email'];
            $customerName = (string) ($row['customer_name'] ?: $email);
            $orderNumber = (string) $row['order_number'];

            if (!$orderCreated) {
                continue;
            }

            $salesChannelIdHex = is_string($salesChannelId) ? Uuid::fromBytesToHex($salesChannelId) : null;
            $languageIdHex = is_string($languageId) ? Uuid::fromBytesToHex($languageId) : null;

            $pickupWindowDays = (int) ($this->systemConfig->get('FoerdeClickCollect.config.pickupWindowDays', $salesChannelIdHex) ?? 2);
            $storeName = (string) ($this->systemConfig->get('FoerdeClickCollect.config.storeName', $salesChannelIdHex) ?? 'Ihr Markt');
            $storeAddress = (string) ($this->systemConfig->get('FoerdeClickCollect.config.storeAddress', $salesChannelIdHex) ?? '');
            $openingHoursCfg = (string) ($this->systemConfig->get('FoerdeClickCollect.config.storeOpeningHours', $salesChannelIdHex) ?? '');

            $expiry = $orderCreated->modify('+' . $pickupWindowDays . ' days');
            if ($expiry < $now) {
                // outside pickup window; skip
                continue;
            }

            $senderName = (string) ($this->systemConfig->get('core.mailerSettings.senderName', $salesChannelIdHex) ?? $storeName);
            $senderEmail = (string) ($this->systemConfig->get('core.mailerSettings.senderAddress', $salesChannelIdHex) ?? 'no-reply@example.com');

            $subjectFromTemplate = null;
            $contentHtmlFromTemplate = '';
            $contentPlainFromTemplate = '';
            // Resolve DB template translation (language, fallback to default language, then last translation)
            $templateTrans = null;
            if (is_string($languageId)) {
                $templateTrans = $this->connection->fetchAssociative(
                    'SELECT subject, content_html, content_plain FROM mail_template_translation WHERE mail_template_id = :tid AND language_id = :lid',
                    ['tid' => $templateId, 'lid' => $languageId]
                );
            }
            if (!$templateTrans) {
                $defaultLang = hex2bin('2fbb5fe2e29a4d70aa5854ce7ce3e20b');
                $templateTrans = $this->connection->fetchAssociative(
                    'SELECT subject, content_html, content_plain FROM mail_template_translation WHERE mail_template_id = :tid AND language_id = :lid',
                    ['tid' => $templateId, 'lid' => $defaultLang]
                );
            }
            if (!$templateTrans) {
                $templateTrans = $this->connection->fetchAssociative(
                    'SELECT subject, content_html, content_plain FROM mail_template_translation WHERE mail_template_id = :tid ORDER BY created_at DESC LIMIT 1',
                    ['tid' => $templateId]
                );
            }

            if (!$templateTrans) {
                $io->error(sprintf('No translation found for reminder template. Cannot send reminder for order #%s', $orderNumber));
                return Command::FAILURE;
            }

            $subjectFromTemplate = (string) ($templateTrans['subject'] ?? '');
            $contentHtmlFromTemplate = (string) ($templateTrans['content_html'] ?? '');
            $contentPlainFromTemplate = (string) ($templateTrans['content_plain'] ?? '');

            if ($subjectFromTemplate === '' && $contentHtmlFromTemplate === '' && $contentPlainFromTemplate === '') {
                $io->error('Reminder template translation has no subject or content. Aborting.');
                return Command::FAILURE;
            }

            $data = [
                'recipients' => [ $email => $customerName ],
                ...(isset($salesChannelIdHex) ? ['salesChannelId' => $salesChannelIdHex] : []),
                ...(isset($languageIdHex) ? ['languageId' => $languageIdHex] : []),
                'senderName' => $senderName,
                'senderEmail' => $senderEmail,
                'contentHtml' => $contentHtmlFromTemplate,
                'contentPlain' => $contentPlainFromTemplate,
                'subject' => $subjectFromTemplate,
            ];

            $templateData = [
                'orderNumber' => $orderNumber,
                'config' => [
                    'storeName' => $storeName,
                    'storeAddress' => $storeAddress,
                    'openingHours' => $openingHoursCfg,
                    'pickupWindowDays' => $pickupWindowDays,
                ],
            ];

            try {
                $this->mailService->send($data, \Shopware\Core\Framework\Context::createDefaultContext(), $templateData);
                $sent++;
            } catch (\Throwable $e) {
                $io->error(sprintf('Failed to send reminder for order #%s: %s', $orderNumber, $e->getMessage()));
            }
        }

        $io->success(sprintf('Reminder dispatch finished. Sent: %d', $sent));
        return Command::SUCCESS;
    }
}
