<?php

namespace plugin\admin\app\service;

use app\service\RedisThirdPartyCommandQueue;
use app\service\SystemSettingService;
use app\service\ThirdPartyCommandQueueInterface;
use app\support\I18n;
use RuntimeException;

class ThirdPartyConnectionAdminService
{
    public function __construct(
        private ?SystemSettingService $settings = null,
        private ?ThirdPartyCommandQueueInterface $queue = null,
        private string $locale = I18n::DEFAULT_LOCALE
    )
    {
        $this->settings ??= new SystemSettingService();
        $this->queue ??= new RedisThirdPartyCommandQueue();
        $this->locale = I18n::normalizeLocale($this->locale);
    }

    public function listSlots(): array
    {
        $config = $this->settings->thirdPartyConfig();
        $capacity = max(1, (int)($config['ws_connection_capacity'] ?? 10));
        $rows = [];
        foreach ($this->slotUrls($config) as $slotId => $url) {
            $state = $this->queue->readSlotState($slotId) ?? [];
            $accountIds = $this->normalizeAccountIds($state['account_ids'] ?? []);
            $updatedAt = (int)($state['updated_at'] ?? 0);
            $rows[] = [
                'slot_id' => $slotId,
                'url' => $url,
                'state' => (string)($state['state'] ?? 'disconnected'),
                'account_ids' => $accountIds,
                'account_ids_text' => implode(', ', $accountIds),
                'account_count' => (int)($state['account_count'] ?? count($accountIds)),
                'capacity' => (int)($state['capacity'] ?? $capacity),
                'last_error' => (string)($state['last_error'] ?? ''),
                'updated_at' => $updatedAt,
                'updated_at_text' => $updatedAt > 0 ? date('Y-m-d H:i:s', $updatedAt) : '',
            ];
        }
        return $rows;
    }

    public function startSlot(string $slotId): array
    {
        $this->assertThirdPartyStartable();
        $this->assertSlotExists($slotId);
        return $this->queue->enqueueStartSlot($slotId);
    }

    public function stopSlot(string $slotId): array
    {
        $this->assertSlotExists($slotId);
        return $this->queue->enqueueStopSlot($slotId, true);
    }

    public function startAllSlots(): array
    {
        $this->assertThirdPartyStartable();
        $this->assertAnySlotExists();
        return $this->queue->enqueueStartAllSlots();
    }

    public function stopAllSlots(): array
    {
        $this->assertAnySlotExists();
        return $this->queue->enqueueStopAllSlots(true);
    }

    private function assertThirdPartyStartable(): void
    {
        $config = $this->settings->thirdPartyConfig();
        if (empty($config['enabled'])) {
            throw new RuntimeException(I18n::t('admin.third_party_connection.third_party_disabled', [], $this->locale));
        }
        if ($this->slotUrls($config) === []) {
            throw new RuntimeException(I18n::t('admin.third_party_connection.websocket_unconfigured', [], $this->locale));
        }
    }

    private function assertAnySlotExists(): void
    {
        if ($this->slotUrls($this->settings->thirdPartyConfig()) === []) {
            throw new RuntimeException(I18n::t('admin.third_party_connection.websocket_unconfigured', [], $this->locale));
        }
    }

    private function assertSlotExists(string $slotId): void
    {
        if (!isset($this->slotUrls($this->settings->thirdPartyConfig())[$slotId])) {
            throw new RuntimeException(I18n::t('admin.third_party_connection.slot_not_found', [], $this->locale));
        }
    }

    private function slotUrls(array $config): array
    {
        $urls = $config['ws_urls'] ?? [];
        if (!is_array($urls)) {
            $urls = [];
        }
        $urls = array_values(array_filter(array_map(
            static fn ($url): string => trim((string)$url),
            $urls
        ), static fn (string $url): bool => $url !== ''));

        if ($urls === []) {
            $legacyUrl = trim((string)($config['ws_url'] ?? ''));
            if ($legacyUrl !== '') {
                $urls = [$legacyUrl];
            }
        }

        $slots = [];
        foreach ($urls as $index => $url) {
            $slots['slot-' . ($index + 1)] = $url;
        }
        return $slots;
    }

    private function normalizeAccountIds(mixed $accountIds): array
    {
        if (!is_array($accountIds)) {
            return [];
        }
        $ids = array_values(array_filter(array_map('intval', $accountIds), static fn (int $id): bool => $id > 0));
        sort($ids);
        return $ids;
    }
}
