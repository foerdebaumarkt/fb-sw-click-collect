<?php declare(strict_types=1);

namespace FoerdeClickCollect\EventSubscriber;

use Doctrine\DBAL\Connection;
use FoerdeClickCollect\Service\PickupConfigResolver;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Mail\Service\MailService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderDeliveryReadySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MailService $mailService,
        private readonly SystemConfigService $systemConfig,
        private readonly LoggerInterface $logger,
        private readonly PickupConfigResolver $pickupConfigResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onStateTransition',
        ];
    }

    public function onStateTransition(StateMachineTransitionEvent $event): void
    {
        if ($event->getEntityName() !== 'order_delivery') {
            return;
        }

        if ($event->getToPlace()->getTechnicalName() !== 'ready') {
            return;
        }

        $deliveryIdHex = $event->getEntityId();
        if (!Uuid::isValid($deliveryIdHex)) {
            $this->logger->warning('[ClickCollect] Transition to ready ignored: invalid delivery id', ['entityId' => $deliveryIdHex]);
            return;
        }

        $deliveryId = Uuid::fromHexToBytes($deliveryIdHex);

        $row = $this->connection->fetchAssociative(
            'SELECT o.order_number, o.sales_channel_id, o.language_id, oc.email, oc.first_name, oc.last_name,
                CONCAT_WS(" ", oc.first_name, oc.last_name) AS customer_name,
                od.custom_fields
             FROM order_delivery od
             INNER JOIN `order` o ON o.id = od.order_id AND o.version_id = od.order_version_id
             INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.version_id = o.version_id
             INNER JOIN shipping_method sm ON sm.id = od.shipping_method_id
             WHERE od.id = :did AND sm.technical_name = :tech',
            ['did' => $deliveryId, 'tech' => 'click_collect']
        );

        if (!$row) {
            return; // not click & collect
        }

        $salesChannelId = $row['sales_channel_id'];
        $languageId = $row['language_id'] ?? null;
        $salesChannelIdHex = Uuid::fromBytesToHex($salesChannelId);
        $languageIdHex = is_string($languageId) ? Uuid::fromBytesToHex($languageId) : null;
        $email = (string) $row['email'];
        $customerName = (string) ($row['customer_name'] ?: $row['email']);
        $orderNumber = (string) $row['order_number'];
        $customerFirstName = trim((string) ($row['first_name'] ?? ''));
        $customerLastName = trim((string) ($row['last_name'] ?? ''));
        if ($customerFirstName === '' && $customerLastName === '' && $customerName !== '' && !filter_var($customerName, FILTER_VALIDATE_EMAIL)) {
            $pieces = preg_split('/\s+/', trim($customerName), 2);
            $customerFirstName = $pieces[0] ?? '';
            $customerLastName = $pieces[1] ?? '';
        }

        $typeId = $this->connection->fetchOne(
            'SELECT id FROM mail_template_type WHERE technical_name = :name',
            ['name' => 'fb_click_collect.ready']
        );
        $templateId = $typeId ? $this->connection->fetchOne(
            'SELECT id FROM mail_template WHERE mail_template_type_id = :typeId ORDER BY created_at DESC LIMIT 1',
            ['typeId' => $typeId]
        ) : null;

        $customFields = $this->decodeCustomFields($row['custom_fields'] ?? null);
        $snapshot = $this->pickupConfigResolver->extractSnapshotFromCustomFields($customFields)
            ?? $this->pickupConfigResolver->resolve($salesChannelIdHex, $languageId);

        $storeName = $snapshot['storeName'];
        $storeAddress = $snapshot['storeAddress'];
        $openingHoursCfg = $snapshot['openingHours'];
        $pickupWindowDays = $snapshot['pickupWindowDays'];
        $pickupPreparationHours = $snapshot['pickupPreparationHours'];

        $configuredSenderName = trim((string) ($this->systemConfig->get('core.mailerSettings.senderName', $salesChannelIdHex) ?? ''));
        $senderName = $configuredSenderName !== '' ? $configuredSenderName : ($storeName !== '' ? $storeName : 'Click & Collect');
        $configuredSenderEmail = trim((string) ($this->systemConfig->get('core.mailerSettings.senderAddress', $salesChannelIdHex) ?? ''));
        $senderEmail = $configuredSenderEmail !== '' ? $configuredSenderEmail : 'no-reply@example.com';

        $subjectFromTemplate = null;
        $contentHtmlFromTemplate = '';
        $contentPlainFromTemplate = '';
        if ($templateId) {
            $templateTrans = null;
            if (\is_string($languageId)) {
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

            if ($templateTrans) {
                $subjectFromTemplate = (string) ($templateTrans['subject'] ?? '');
                $contentHtmlFromTemplate = (string) ($templateTrans['content_html'] ?? '');
                $contentPlainFromTemplate = (string) ($templateTrans['content_plain'] ?? '');
            }
        }

        $data = [
            'recipients' => [ $email => $customerName ],
            'salesChannelId' => $salesChannelIdHex,
            ...(isset($languageIdHex) ? ['languageId' => $languageIdHex] : []),
            'senderName' => $senderName,
            'senderEmail' => $senderEmail,
            'contentHtml' => $contentHtmlFromTemplate,
            'contentPlain' => $contentPlainFromTemplate,
            'subject' => $subjectFromTemplate ?: sprintf('Ihre Bestellung #%s ist abholbereit', $orderNumber),
        ];

        $templateData = [
            'orderNumber' => $orderNumber,
            'customer' => [
                'firstName' => $customerFirstName,
                'lastName' => $customerLastName,
                'email' => $email,
            ],
            'config' => [
                'storeName' => $storeName,
                'storeAddress' => $storeAddress !== '' ? $storeAddress : null,
                'openingHours' => $openingHoursCfg !== '' ? $openingHoursCfg : null,
                'pickupWindowDays' => $pickupWindowDays,
                'pickupPreparationHours' => $pickupPreparationHours,
            ],
            'storeName' => $storeName,
            'storeAddress' => $storeAddress,
            'openingHours' => $openingHoursCfg,
            'pickupWindowDays' => $pickupWindowDays,
        ];

        try {
            $this->mailService->send($data, $event->getContext(), $templateData);
            $this->logger->info('[ClickCollect] Ready-for-pickup mail sent', ['orderNumber' => $orderNumber, 'email' => $email]);
            return;
        } catch (ConstraintViolationException $e) {
            $this->logger->error('[ClickCollect] Ready mail validation failed; retrying with plain content', [
                'orderNumber' => $orderNumber,
                'email' => $email,
            ]);

            $openingHtml = $openingHoursCfg !== ''
                ? '<br/><em>Oeffnungszeiten:</em><br/>' . nl2br(htmlspecialchars($openingHoursCfg))
                : '';
            $fallbackHtml = sprintf(
                '<p>Hallo %s,</p>' .
                '<p>Ihre Click & Collect Bestellung <strong>#%s</strong> ist abholbereit und liegt fuer Sie im Markt bereit.</p>' .
                '<p><strong>Abholung</strong><br/>%s<br/>%s%s</p>' .
                '<p><strong>Bitte mitbringen</strong></p>' .
                '<ul><li>Bestellnummer <strong>#%s</strong></li><li>Diese E-Mail (optional)</li></ul>' .
                '<p><strong>Hinweis</strong><br/>Zur Abholung genuegt es, Ihren Namen zu nennen; die Bezahlung erfolgt im Markt.</p>' .
                '<p><strong>Abholhinweise</strong><br/>Bitte holen Sie die Ware innerhalb von <strong>%d</strong> Tagen ab.</p>' .
                '<p>Vielen Dank und bis bald!<br/>Ihr Foerde Baumarkt Team</p>',
                htmlspecialchars($customerName),
                htmlspecialchars($orderNumber),
                htmlspecialchars($storeName),
                nl2br(htmlspecialchars($storeAddress)),
                $openingHtml,
                htmlspecialchars($orderNumber),
                $pickupWindowDays
            );

            $openingPlain = $openingHoursCfg !== ''
                ? "\n\nOeffnungszeiten:\n" . $openingHoursCfg
                : '';
            $fallbackText = sprintf(
                "Hallo %s\n\n" .
                "Ihre Click & Collect Bestellung #%s ist abholbereit und liegt fuer Sie im Markt bereit.\n\n" .
                "Abholung:\n%s\n%s%s\n\n" .
                "Bitte mitbringen:\n- Bestellnummer #%s\n- Diese E-Mail (optional)\n\n" .
                "Hinweis:\nZur Abholung genuegt es, Ihren Namen zu nennen; die Bezahlung erfolgt im Markt.\n\n" .
                "Abholhinweise:\nBitte holen Sie die Ware innerhalb von %d Tagen ab.\n\n" .
                "Vielen Dank und bis bald!\nIhr Foerde Baumarkt Team\n",
                $customerName,
                $orderNumber,
                $storeName,
                $storeAddress,
                $openingPlain,
                $orderNumber,
                $pickupWindowDays
            );

            $fallbackData = [
                'recipients' => [ $email => $customerName ],
                'salesChannelId' => $salesChannelIdHex,
                ...(isset($languageIdHex) ? ['languageId' => $languageIdHex] : []),
                'senderName' => $senderName,
                'senderEmail' => $senderEmail,
                'subject' => sprintf('Ihre Bestellung #%s ist abholbereit', $orderNumber),
                'contentHtml' => $fallbackHtml,
                'contentPlain' => $fallbackText,
            ];

            try {
                $this->mailService->send($fallbackData, $event->getContext());
                $this->logger->info('[ClickCollect] Ready-for-pickup mail sent via fallback content', [
                    'orderNumber' => $orderNumber,
                    'email' => $email,
                ]);
                return;
            } catch (\Throwable $e2) {
                $this->logger->error('[ClickCollect] Fallback ready mail failed', [
                    'orderNumber' => $orderNumber,
                    'email' => $email,
                    'error' => $e2->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[ClickCollect] Failed to send ready-for-pickup mail', [
                'error' => $e->getMessage(),
                'orderNumber' => $orderNumber,
                'email' => $email,
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeCustomFields(null|string $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
