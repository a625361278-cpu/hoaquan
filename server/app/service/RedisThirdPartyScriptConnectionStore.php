<?php

namespace app\service;

use support\Redis;

class RedisThirdPartyScriptConnectionStore implements ThirdPartyScriptConnectionStoreInterface
{
    public const PREFIX = 'gameassist:third_party_scripts:';
    private const CONNECTION_TTL = 180;

    private function idleKey(): string
    {
        return self::PREFIX . 'idle';
    }

    private function boundKey(): string
    {
        return self::PREFIX . 'bound';
    }

    private function connectionKey(string $clientId): string
    {
        return self::PREFIX . 'connections:' . $clientId;
    }

    private function accountKey(int $accountId): string
    {
        return self::PREFIX . 'accounts:' . $accountId;
    }

    public function registerIdle(string $clientId, array $metadata = []): array
    {
        $now = time();
        $state = [
            'client_id' => $clientId,
            'state' => 'idle',
            'account_id' => 0,
            'session_id' => '',
            'request_id' => '',
            'remote_ip' => (string)($metadata['remote_ip'] ?? ''),
            'script_version' => (string)($metadata['script_version'] ?? ''),
            'connected_at' => $now,
            'last_seen' => $now,
            'last_error' => '',
        ];

        $this->writeConnection($clientId, $state);
        Redis::sAdd($this->idleKey(), $clientId);
        Redis::sRem($this->boundKey(), $clientId);
        return $state;
    }

    public function heartbeat(string $clientId, array $metadata = []): ?array
    {
        $state = $this->connection($clientId);
        if (!$state) {
            return null;
        }

        $state['last_seen'] = time();
        if (isset($metadata['script_version'])) {
            $state['script_version'] = (string)$metadata['script_version'];
        }
        $this->writeConnection($clientId, $state);
        return $state;
    }

    public function connection(string $clientId): ?array
    {
        $payload = Redis::get($this->connectionKey($clientId));
        if (!$payload) {
            Redis::sRem($this->idleKey(), $clientId);
            Redis::sRem($this->boundKey(), $clientId);
            return null;
        }

        $state = json_decode((string)$payload, true);
        return is_array($state) ? $state : null;
    }

    public function connectionByAccount(int $accountId): ?array
    {
        $clientId = Redis::get($this->accountKey($accountId));
        if (!$clientId) {
            return null;
        }

        $state = $this->connection((string)$clientId);
        if (!$state) {
            Redis::del($this->accountKey($accountId));
            return null;
        }
        return $state;
    }

    public function allocateIdle(int $accountId, string $sessionId, string $requestId): ?array
    {
        while ($clientId = Redis::sPop($this->idleKey())) {
            $clientId = (string)$clientId;
            $state = $this->connection($clientId);
            if (!$state || ($state['state'] ?? '') !== 'idle') {
                continue;
            }

            $now = time();
            $state['state'] = 'bound';
            $state['account_id'] = $accountId;
            $state['session_id'] = $sessionId;
            $state['request_id'] = $requestId;
            $state['bound_at'] = $now;
            $state['last_seen'] = $now;
            $state['last_error'] = '';
            $this->writeConnection($clientId, $state);
            Redis::setEx($this->accountKey($accountId), self::CONNECTION_TTL, $clientId);
            Redis::sAdd($this->boundKey(), $clientId);
            return $state;
        }

        return null;
    }

    public function markStopping(int $accountId): ?array
    {
        $state = $this->connectionByAccount($accountId);
        if (!$state) {
            return null;
        }

        $state['state'] = 'stopping';
        $state['last_seen'] = time();
        $this->writeConnection((string)$state['client_id'], $state);
        return $state;
    }

    public function releaseClient(string $clientId): ?array
    {
        $state = $this->connection($clientId);
        Redis::del($this->connectionKey($clientId));
        Redis::sRem($this->idleKey(), $clientId);
        Redis::sRem($this->boundKey(), $clientId);
        if ($state && (int)($state['account_id'] ?? 0) > 0) {
            Redis::del($this->accountKey((int)$state['account_id']));
        }
        return $state;
    }

    public function listConnections(): array
    {
        $clientIds = array_values(array_unique(array_merge(
            $this->setMembers($this->idleKey()),
            $this->setMembers($this->boundKey())
        )));

        $rows = [];
        foreach ($clientIds as $clientId) {
            $state = $this->connection((string)$clientId);
            if (!$state) {
                continue;
            }
            $rows[] = $state;
        }

        usort($rows, static function (array $a, array $b): int {
            return ((int)($b['connected_at'] ?? 0)) <=> ((int)($a['connected_at'] ?? 0));
        });
        return $rows;
    }

    public function stats(): array
    {
        $rows = $this->listConnections();
        $stats = [
            'online_count' => count($rows),
            'idle_count' => 0,
            'bound_count' => 0,
            'stopping_count' => 0,
        ];
        foreach ($rows as $row) {
            $state = (string)($row['state'] ?? '');
            if ($state === 'idle') {
                $stats['idle_count']++;
            } elseif ($state === 'bound') {
                $stats['bound_count']++;
            } elseif ($state === 'stopping') {
                $stats['stopping_count']++;
            }
        }
        return $stats;
    }

    private function writeConnection(string $clientId, array $state): void
    {
        Redis::setEx($this->connectionKey($clientId), self::CONNECTION_TTL, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function setMembers(string $key): array
    {
        $members = Redis::sMembers($key);
        return is_array($members) ? $members : [];
    }
}
