<?php

namespace app\service;

use support\Redis;

class RedisThirdPartyScriptConnectionStore implements ThirdPartyScriptConnectionStoreInterface
{
    public const PREFIX = 'gameassist:third_party_scripts:';
    private const CONNECTION_TTL = 180;

    public function __construct(private mixed $redis = null)
    {
    }

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
            'validation_id' => '',
            'remote_ip' => (string)($metadata['remote_ip'] ?? ''),
            'peer_ip' => (string)($metadata['peer_ip'] ?? ''),
            'peer_port' => (int)($metadata['peer_port'] ?? 0),
            'script_version' => (string)($metadata['script_version'] ?? ''),
            'connected_at' => $now,
            'last_seen' => $now,
            'last_message_at' => $now,
            'last_message_type' => 'connected',
            'last_message_bytes' => 0,
            'last_heartbeat_at' => 0,
            'message_count' => 0,
            'heartbeat_count' => 0,
            'last_error' => '',
        ];

        $this->writeConnection($clientId, $state);
        $this->redis()->sAdd($this->idleKey(), $clientId);
        $this->redis()->sRem($this->boundKey(), $clientId);
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
        if (isset($metadata['message_type'])) {
            $messageType = (string)$metadata['message_type'];
            $state['last_message_at'] = time();
            $state['last_message_type'] = $messageType;
            $state['last_message_bytes'] = max(0, (int)($metadata['message_bytes'] ?? 0));
            $state['message_count'] = (int)($state['message_count'] ?? 0) + 1;
            if (in_array($messageType, ['heartbeat', 'ping', 'pong'], true)) {
                $state['last_heartbeat_at'] = time();
                $state['heartbeat_count'] = (int)($state['heartbeat_count'] ?? 0) + 1;
            }
        }
        $this->writeConnection($clientId, $state);
        $accountId = (int)($state['account_id'] ?? 0);
        if ($accountId > 0 && in_array((string)($state['state'] ?? ''), ['bound', 'stopping'], true)) {
            $this->redis()->setEx($this->accountKey($accountId), self::CONNECTION_TTL, $clientId);
        }
        return $state;
    }

    public function connection(string $clientId): ?array
    {
        $payload = $this->redis()->get($this->connectionKey($clientId));
        if (!$payload) {
            $this->redis()->sRem($this->idleKey(), $clientId);
            $this->redis()->sRem($this->boundKey(), $clientId);
            return null;
        }

        $state = json_decode((string)$payload, true);
        return is_array($state) ? $state : null;
    }

    public function connectionByAccount(int $accountId): ?array
    {
        $clientId = $this->redis()->get($this->accountKey($accountId));
        if (!$clientId) {
            return $this->findBoundConnectionByAccount($accountId);
        }

        $state = $this->connection((string)$clientId);
        if (!$state) {
            $this->redis()->del($this->accountKey($accountId));
            return $this->findBoundConnectionByAccount($accountId);
        }
        return $state;
    }

    public function allocateIdle(int $accountId, string $sessionId, string $requestId): ?array
    {
        while ($clientId = $this->redis()->sPop($this->idleKey())) {
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
            $this->redis()->setEx($this->accountKey($accountId), self::CONNECTION_TTL, $clientId);
            $this->redis()->sAdd($this->boundKey(), $clientId);
            return $state;
        }

        return null;
    }

    public function allocateIdleForValidation(string $validationId, string $sessionId, string $requestId): ?array
    {
        while ($clientId = $this->redis()->sPop($this->idleKey())) {
            $clientId = (string)$clientId;
            $state = $this->connection($clientId);
            if (!$state || ($state['state'] ?? '') !== 'idle') {
                continue;
            }

            $now = time();
            $state['state'] = 'validating';
            $state['account_id'] = 0;
            $state['validation_id'] = $validationId;
            $state['session_id'] = $sessionId;
            $state['request_id'] = $requestId;
            $state['bound_at'] = $now;
            $state['last_seen'] = $now;
            $state['last_error'] = '';
            $this->writeConnection($clientId, $state);
            $this->redis()->sAdd($this->boundKey(), $clientId);
            return $state;
        }

        return null;
    }

    public function restoreValidationToIdle(string $clientId, string $validationId, string $sessionId, string $requestId): ?array
    {
        $key = $this->connectionKey($clientId);
        $script = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return false end
local state = cjson.decode(raw)
if state['state'] ~= 'validating'
    or tostring(state['validation_id'] or '') ~= ARGV[1]
    or tostring(state['session_id'] or '') ~= ARGV[2]
    or tostring(state['request_id'] or '') ~= ARGV[3] then
    return false
end
state['state'] = 'idle'
state['account_id'] = 0
state['validation_id'] = ''
state['session_id'] = ''
state['request_id'] = ''
state['bound_at'] = 0
state['last_seen'] = tonumber(ARGV[4])
redis.call('SETEX', KEYS[1], tonumber(ARGV[5]), cjson.encode(state))
redis.call('SREM', KEYS[2], ARGV[6])
redis.call('SADD', KEYS[3], ARGV[6])
return cjson.encode(state)
LUA;
        $result = $this->evalScript($script, [
            $key,
            $this->boundKey(),
            $this->idleKey(),
            $validationId,
            $sessionId,
            $requestId,
            (string)time(),
            (string)self::CONNECTION_TTL,
            $clientId,
        ], 3);
        if (!is_string($result) || $result === '') {
            return null;
        }
        $state = json_decode($result, true);
        return is_array($state) ? $state : null;
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
        $this->redis()->del($this->connectionKey($clientId));
        $this->redis()->sRem($this->idleKey(), $clientId);
        $this->redis()->sRem($this->boundKey(), $clientId);
        if ($state && (int)($state['account_id'] ?? 0) > 0) {
            $this->redis()->del($this->accountKey((int)$state['account_id']));
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
            'validating_count' => 0,
        ];
        foreach ($rows as $row) {
            $state = (string)($row['state'] ?? '');
            if ($state === 'idle') {
                $stats['idle_count']++;
            } elseif ($state === 'bound') {
                $stats['bound_count']++;
            } elseif ($state === 'stopping') {
                $stats['stopping_count']++;
            } elseif ($state === 'validating') {
                $stats['validating_count']++;
            }
        }
        return $stats;
    }

    private function writeConnection(string $clientId, array $state): void
    {
        $this->redis()->setEx($this->connectionKey($clientId), self::CONNECTION_TTL, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function setMembers(string $key): array
    {
        $members = $this->redis()->sMembers($key);
        return is_array($members) ? $members : [];
    }

    private function findBoundConnectionByAccount(int $accountId): ?array
    {
        foreach ($this->setMembers($this->boundKey()) as $clientId) {
            $clientId = (string)$clientId;
            $state = $this->connection($clientId);
            if (!$state) {
                continue;
            }
            if ((int)($state['account_id'] ?? 0) !== $accountId) {
                continue;
            }
            if (!in_array((string)($state['state'] ?? ''), ['bound', 'stopping'], true)) {
                continue;
            }

            $this->redis()->setEx($this->accountKey($accountId), self::CONNECTION_TTL, $clientId);
            return $state;
        }

        return null;
    }

    private function redis(): mixed
    {
        return $this->redis ?? Redis::connection();
    }

    private function evalScript(string $script, array $arguments, int $keyCount): mixed
    {
        return $this->redis()->eval($script, $keyCount, ...$arguments);
    }
}
