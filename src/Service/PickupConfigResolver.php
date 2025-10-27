<?php declare(strict_types=1);

namespace FoerdeClickCollect\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Centralizes Click & Collect store configuration resolution and snapshot handling.
 */
class PickupConfigResolver
{
    public const FIELD_STORE_NAME = 'foerde_click_collect_store_name';
    public const FIELD_STORE_ADDRESS = 'foerde_click_collect_store_address';
    public const FIELD_OPENING_HOURS = 'foerde_click_collect_opening_hours';
    public const FIELD_PICKUP_WINDOW_DAYS = 'foerde_click_collect_pickup_window_days';
    public const FIELD_PICKUP_PREPARATION_HOURS = 'foerde_click_collect_pickup_preparation_hours';

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Resolve store configuration for a given sales channel/language.
     *
     * @param string $salesChannelId Sales channel id (hex string or binary).
     * @param string|null $languageId Language id (hex string or binary) for locale-sensitive fallbacks.
     *
     * @return array{storeName:string,storeAddress:string,openingHours:string,pickupWindowDays:int,pickupPreparationHours:int,localeCode: ?string}
     */
    public function resolve(string $salesChannelId, ?string $languageId): array
    {
        $salesChannelHex = $this->normalizeToHex($salesChannelId);
        $languageBytes = $languageId ? $this->normalizeToBytes($languageId) : null;
        $localeCode = $this->resolveLocaleCode($languageBytes);

        $storeName = $this->sanitizeString($this->systemConfig->get('FoerdeClickCollect.config.storeName', $salesChannelHex));
        if ($storeName === '') {
            $storeName = $this->sanitizeString($this->systemConfig->get('FoerdeClickCollect.config.storeName'));
        }
        if ($storeName === '') {
            $resolvedChannelName = $this->resolveSalesChannelName($salesChannelId, $languageBytes);
            if ($resolvedChannelName !== null) {
                $storeName = $this->sanitizeString($resolvedChannelName);
            }
        }
        if ($storeName === '') {
            $storeName = $this->fallbackStoreName($localeCode);
        }

        $storeAddress = $this->sanitizeString($this->systemConfig->get('FoerdeClickCollect.config.storeAddress', $salesChannelHex));
        if ($storeAddress === '') {
            $storeAddress = $this->sanitizeString($this->systemConfig->get('FoerdeClickCollect.config.storeAddress'));
        }
        if ($storeAddress === '') {
            $basicAddress = $this->sanitizeString($this->systemConfig->get('core.basicInformation.address', $salesChannelHex));
            if ($basicAddress === '') {
                $basicAddress = $this->sanitizeString($this->systemConfig->get('core.basicInformation.address'));
            }
            $storeAddress = $basicAddress;
        }

        $openingHours = $this->sanitizeString($this->systemConfig->get('FoerdeClickCollect.config.storeOpeningHours', $salesChannelHex));
        if ($openingHours === '') {
            $openingHours = $this->sanitizeString($this->systemConfig->get('FoerdeClickCollect.config.storeOpeningHours'));
        }

        $pickupWindowConfigured = $this->systemConfig->get('FoerdeClickCollect.config.pickupWindowDays', $salesChannelHex);
        if (!is_numeric($pickupWindowConfigured)) {
            $pickupWindowConfigured = $this->systemConfig->get('FoerdeClickCollect.config.pickupWindowDays');
        }
        $pickupWindowDays = $this->sanitizeInt($pickupWindowConfigured, 2);

        $pickupPrepConfigured = $this->systemConfig->get('FoerdeClickCollect.config.pickupPreparationHours', $salesChannelHex);
        if (!is_numeric($pickupPrepConfigured)) {
            $pickupPrepConfigured = $this->systemConfig->get('FoerdeClickCollect.config.pickupPreparationHours');
        }
        $pickupPreparationHours = $this->sanitizeInt($pickupPrepConfigured, 4);

        return [
            'storeName' => $storeName,
            'storeAddress' => $storeAddress,
            'openingHours' => $openingHours,
            'pickupWindowDays' => max(0, $pickupWindowDays),
            'pickupPreparationHours' => max(0, $pickupPreparationHours),
            'localeCode' => $localeCode,
        ];
    }

    /**
     * Extract snapshot data from custom fields if available.
     *
     * @param array<string,mixed>|null $customFields
     *
     * @return array{storeName:string,storeAddress:string,openingHours:string,pickupWindowDays:int,pickupPreparationHours:int}|null
     */
    public function extractSnapshotFromCustomFields(?array $customFields): ?array
    {
        if (!$customFields) {
            return null;
        }

        $storeName = $this->sanitizeString($customFields[self::FIELD_STORE_NAME] ?? null);
        $storeAddress = $this->sanitizeString($customFields[self::FIELD_STORE_ADDRESS] ?? null);
        $openingHours = $this->sanitizeString($customFields[self::FIELD_OPENING_HOURS] ?? null);
        $windowDays = $this->sanitizeInt($customFields[self::FIELD_PICKUP_WINDOW_DAYS] ?? null, 2);
        $prepHours = $this->sanitizeInt($customFields[self::FIELD_PICKUP_PREPARATION_HOURS] ?? null, 4);

        $hasContent = $storeName !== '' || $storeAddress !== '' || $openingHours !== '';
        if (!$hasContent && $windowDays === 0 && $prepHours === 0) {
            return null;
        }

        return [
            'storeName' => $storeName,
            'storeAddress' => $storeAddress,
            'openingHours' => $openingHours,
            'pickupWindowDays' => $windowDays,
            'pickupPreparationHours' => $prepHours,
        ];
    }

    /**
     * Merge a snapshot into existing custom fields.
     *
     * @param array<string,mixed> $existing
     * @param array{storeName:string,storeAddress:string,openingHours:string,pickupWindowDays:int,pickupPreparationHours:int} $snapshot
     *
     * @return array<string,mixed>
     */
    public function applySnapshotToCustomFields(array $existing, array $snapshot): array
    {
        $fields = $existing;
        $fields[self::FIELD_STORE_NAME] = $snapshot['storeName'] !== '' ? $snapshot['storeName'] : null;
        $fields[self::FIELD_STORE_ADDRESS] = $snapshot['storeAddress'] !== '' ? $snapshot['storeAddress'] : null;
        $fields[self::FIELD_OPENING_HOURS] = $snapshot['openingHours'] !== '' ? $snapshot['openingHours'] : null;
        $fields[self::FIELD_PICKUP_WINDOW_DAYS] = $snapshot['pickupWindowDays'];
        $fields[self::FIELD_PICKUP_PREPARATION_HOURS] = $snapshot['pickupPreparationHours'];

        return $fields;
    }

    /**
     * Utility to compare snapshot content against existing custom fields.
     */
    public function snapshotDiffers(array $existing, array $snapshot): bool
    {
        $current = $this->extractSnapshotFromCustomFields($existing) ?? [
            'storeName' => '',
            'storeAddress' => '',
            'openingHours' => '',
            'pickupWindowDays' => 0,
            'pickupPreparationHours' => 0,
        ];

        return $this->normaliseForComparison($this->normaliseSnapshot($current)) !== $this->normaliseForComparison($this->normaliseSnapshot($snapshot));
    }

    /**
     * Ensure hex string format for ids.
     */
    private function normalizeToHex(string $id): string
    {
        if (preg_match('/^[0-9a-f]{32}$/i', $id) === 1) {
            return strtolower($id);
        }

        if (strlen($id) === 16) {
            return Uuid::fromBytesToHex($id);
        }

        throw new \InvalidArgumentException('Expected hex or binary uuid string.');
    }

    private function normalizeToBytes(string $id): string
    {
        if (strlen($id) === 16) {
            return $id;
        }

        if (preg_match('/^[0-9a-f]{32}$/i', $id) === 1) {
            return Uuid::fromHexToBytes($id);
        }

        throw new \InvalidArgumentException('Expected hex or binary uuid string.');
    }

    private function sanitizeString(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^\$+$/', $trimmed) === 1) {
            return '';
        }

        if (preg_match('/^\$\{.*\}$/', $trimmed) === 1) {
            return '';
        }

        if ($trimmed === '-' || $trimmed === '--' || strcasecmp($trimmed, 'n/a') === 0) {
            return '';
        }

        return $trimmed;
    }

    private function sanitizeInt(mixed $value, int $default): int
    {
        if ($value === null) {
            return $default;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function resolveLocaleCode(?string $languageId): ?string
    {
        $languageBytes = $languageId;
        if (!$languageBytes) {
            $languageBytes = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        }

        $code = $this->connection->fetchOne(
            'SELECT locale.code FROM language INNER JOIN locale ON locale.id = language.locale_id WHERE language.id = :id',
            ['id' => $languageBytes]
        );

        return is_string($code) && $code !== '' ? $code : null;
    }

    private function resolveSalesChannelName(string $salesChannelId, ?string $languageId): ?string
    {
        $languageBytes = $languageId ?: Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        $salesChannelBytes = strlen($salesChannelId) === 16 ? $salesChannelId : Uuid::fromHexToBytes($this->normalizeToHex($salesChannelId));

        $name = $this->connection->fetchOne(
            'SELECT name FROM sales_channel_translation WHERE sales_channel_id = :sid AND language_id = :lid',
            ['sid' => $salesChannelBytes, 'lid' => $languageBytes]
        );

        if (!is_string($name) || trim($name) === '') {
            $fallbackLang = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
            $name = $this->connection->fetchOne(
                'SELECT name FROM sales_channel_translation WHERE sales_channel_id = :sid AND language_id = :lid',
                ['sid' => $salesChannelBytes, 'lid' => $fallbackLang]
            );
        }

        return is_string($name) ? $name : null;
    }

    private function fallbackStoreName(?string $localeCode): string
    {
        if (is_string($localeCode) && str_starts_with($localeCode, 'de')) {
            return 'Ihr Markt';
        }

        return 'Your store';
    }

    /**
     * Normalise arrays for comparison by encoding to JSON deterministically.
     */
    private function normaliseForComparison(array $data): string
    {
        ksort($data);
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /** @param array{storeName:string,storeAddress:string,openingHours:string,pickupWindowDays:int,pickupPreparationHours:int} $snapshot */
    private function normaliseSnapshot(array $snapshot): array
    {
        return [
            'storeName' => $snapshot['storeName'],
            'storeAddress' => $snapshot['storeAddress'],
            'openingHours' => $snapshot['openingHours'],
            'pickupWindowDays' => (int) $snapshot['pickupWindowDays'],
            'pickupPreparationHours' => (int) $snapshot['pickupPreparationHours'],
        ];
    }
}
