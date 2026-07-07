<?php

namespace plugin\admin\app\service;

use app\service\RedisThirdPartyScriptConnectionStore;
use app\service\GameLogQueue;
use app\service\ThirdPartyScriptConnectionStoreInterface;
use app\support\I18n;

class ThirdPartyConnectionAdminService
{
    public function __construct(
        private ?ThirdPartyScriptConnectionStoreInterface $connections = null,
        private ?GameLogQueue $logQueue = null,
        private string $locale = I18n::DEFAULT_LOCALE
    )
    {
        $this->connections ??= new RedisThirdPartyScriptConnectionStore();
        $this->logQueue ??= new GameLogQueue();
        $this->locale = I18n::normalizeLocale($this->locale);
    }

    public function listConnections(): array
    {
        return array_map([$this, 'publicConnection'], $this->connections->listConnections());
    }

    public function summary(): array
    {
        return $this->connections->stats() + [
            'log_queue' => $this->logQueue->stats(),
        ];
    }

    private function publicConnection(array $state): array
    {
        $connectedAt = (int)($state['connected_at'] ?? 0);
        $lastSeen = (int)($state['last_seen'] ?? 0);
        $accountId = (int)($state['account_id'] ?? 0);

        return [
            'client_id' => (string)($state['client_id'] ?? ''),
            'state' => (string)($state['state'] ?? 'idle'),
            'account_id' => $accountId,
            'account_id_text' => $accountId > 0 ? (string)$accountId : '',
            'session_id' => (string)($state['session_id'] ?? ''),
            'request_id' => (string)($state['request_id'] ?? ''),
            'remote_ip' => (string)($state['remote_ip'] ?? ''),
            'script_version' => (string)($state['script_version'] ?? ''),
            'last_error' => (string)($state['last_error'] ?? ''),
            'connected_at' => $connectedAt,
            'connected_at_text' => $connectedAt > 0 ? date('Y-m-d H:i:s', $connectedAt) : '',
            'last_seen' => $lastSeen,
            'last_seen_text' => $lastSeen > 0 ? date('Y-m-d H:i:s', $lastSeen) : '',
        ];
    }
}
