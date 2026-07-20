<?php

namespace tests\Feature;

use app\service\RedisThirdPartyScriptConnectionStore;
use PHPUnit\Framework\TestCase;

class RedisThirdPartyScriptConnectionStoreTest extends TestCase
{
    public function testHeartbeatRefreshesBoundAccountIndex(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new RedisThirdPartyScriptConnectionStore($redis);
        $store->registerIdle('client-1');
        $store->allocateIdle(3, 'session-1', 'request-1');

        $redis->expire($this->accountKey(3), 1);
        $store->heartbeat('client-1');

        $this->assertSame(180, $redis->ttl($this->accountKey(3)));
    }

    public function testHeartbeatRecordsMessageDiagnosticsWithoutSensitivePayload(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new RedisThirdPartyScriptConnectionStore($redis);
        $store->registerIdle('client-1', [
            'remote_ip' => '203.0.113.10',
            'peer_ip' => '127.0.0.1',
            'peer_port' => 54321,
        ]);

        $store->heartbeat('client-1', [
            'script_version' => '1.0.0',
            'message_type' => 'heartbeat',
            'message_bytes' => 51,
        ]);
        $store->heartbeat('client-1', [
            'message_type' => 'status',
            'message_bytes' => 128,
        ]);

        $state = $store->connection('client-1');
        $this->assertSame('203.0.113.10', $state['remote_ip']);
        $this->assertSame('127.0.0.1', $state['peer_ip']);
        $this->assertSame(54321, $state['peer_port']);
        $this->assertSame('status', $state['last_message_type']);
        $this->assertSame(128, $state['last_message_bytes']);
        $this->assertSame(2, $state['message_count']);
        $this->assertSame(1, $state['heartbeat_count']);
        $this->assertGreaterThan(0, $state['last_heartbeat_at']);
        $this->assertArrayNotHasKey('message_payload', $state);
    }

    public function testConnectionByAccountRepairsMissingAccountIndexFromBoundConnection(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new RedisThirdPartyScriptConnectionStore($redis);
        $store->registerIdle('client-1');
        $store->allocateIdle(3, 'session-1', 'request-1');
        $redis->del($this->accountKey(3));

        $connection = $store->connectionByAccount(3);

        $this->assertSame('client-1', $connection['client_id']);
        $this->assertSame('bound', $connection['state']);
        $this->assertSame('client-1', $redis->get($this->accountKey(3)));
        $this->assertSame(180, $redis->ttl($this->accountKey(3)));
    }

    public function testReleasingOldClientDoesNotDeleteNewAccountIndex(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new RedisThirdPartyScriptConnectionStore($redis);
        $store->registerIdle('old-client');
        $store->registerIdle('new-client');
        $store->allocateIdle(3, 'old-session', 'old-request');
        $store->allocateIdle(3, 'new-session', 'new-request');

        $released = $store->releaseClient('old-client');

        $this->assertSame('old-client', $released['client_id']);
        $this->assertSame('new-client', $redis->get($this->accountKey(3)));
        $this->assertSame('new-client', $store->connectionByAccount(3)['client_id']);
    }

    public function testStoppingOldClientHeartbeatDoesNotOverwriteNewAccountIndex(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new RedisThirdPartyScriptConnectionStore($redis);
        $store->registerIdle('old-client');
        $store->registerIdle('new-client');
        $store->allocateIdle(3, 'old-session', 'old-request');
        $store->allocateIdle(3, 'new-session', 'new-request');
        $store->markClientStopping('old-client');

        $store->heartbeat('old-client', ['message_type' => 'log']);

        $this->assertSame('new-client', $redis->get($this->accountKey(3)));
        $this->assertSame('new-client', $store->connectionByAccount(3)['client_id']);
    }

    public function testMissingAccountIndexFallbackPrefersBoundConnectionOverStoppingConnection(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new RedisThirdPartyScriptConnectionStore($redis);
        $store->registerIdle('old-client');
        $store->registerIdle('new-client');
        $store->allocateIdle(3, 'old-session', 'old-request');
        $store->allocateIdle(3, 'new-session', 'new-request');
        $store->markClientStopping('old-client');
        $redis->del($this->accountKey(3));

        $connection = $store->connectionByAccount(3);

        $this->assertSame('new-client', $connection['client_id']);
        $this->assertSame('new-client', $redis->get($this->accountKey(3)));
    }

    public function testValidationConnectionOnlyReturnsIdleWhenContextMatches(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new RedisThirdPartyScriptConnectionStore($redis);
        $store->registerIdle('client-1');
        $reserved = $store->allocateIdleForValidation('validation-1', 'session-1', 'request-1');
        $this->assertSame('validating', $reserved['state']);
        $this->assertNull($store->restoreValidationToIdle('client-1', 'wrong', 'session-1', 'request-1'));
        $this->assertSame('validating', $store->connection('client-1')['state']);

        $restored = $store->restoreValidationToIdle('client-1', 'validation-1', 'session-1', 'request-1');
        $this->assertSame('idle', $restored['state']);
        $this->assertSame('', $restored['validation_id']);
        $this->assertSame(1, $store->stats()['idle_count']);
        $this->assertSame(0, $store->stats()['validating_count']);
    }

    public function testDefaultRedisClientIsAConnectionObject(): void
    {
        $store = new RedisThirdPartyScriptConnectionStore();
        $reflection = new \ReflectionMethod($store, 'redis');
        $reflection->setAccessible(true);

        $this->assertIsObject($reflection->invoke($store));
    }

    private function accountKey(int $accountId): string
    {
        return RedisThirdPartyScriptConnectionStore::PREFIX . 'accounts:' . $accountId;
    }
}

class InMemoryRedisClient
{
    private array $strings = [];
    private array $sets = [];
    private array $ttls = [];

    public function setEx(string $key, int $ttl, string $value): bool
    {
        $this->strings[$key] = $value;
        $this->ttls[$key] = $ttl;
        return true;
    }

    public function get(string $key): mixed
    {
        return $this->strings[$key] ?? false;
    }

    public function del(string $key): int
    {
        $removed = isset($this->strings[$key]) || isset($this->sets[$key]) ? 1 : 0;
        unset($this->strings[$key], $this->sets[$key], $this->ttls[$key]);
        return $removed;
    }

    public function sAdd(string $key, string $member): int
    {
        $exists = isset($this->sets[$key][$member]);
        $this->sets[$key][$member] = true;
        return $exists ? 0 : 1;
    }

    public function sRem(string $key, string $member): int
    {
        $exists = isset($this->sets[$key][$member]);
        unset($this->sets[$key][$member]);
        return $exists ? 1 : 0;
    }

    public function sPop(string $key): mixed
    {
        if (empty($this->sets[$key])) {
            return false;
        }
        $member = array_key_first($this->sets[$key]);
        unset($this->sets[$key][$member]);
        return $member;
    }

    public function sMembers(string $key): array
    {
        return array_keys($this->sets[$key] ?? []);
    }

    public function expire(string $key, int $ttl): bool
    {
        if (!isset($this->strings[$key])) {
            return false;
        }
        $this->ttls[$key] = $ttl;
        return true;
    }

    public function ttl(string $key): int
    {
        return $this->ttls[$key] ?? -1;
    }

    public function eval(string $script, int $keyCount, mixed ...$arguments): mixed
    {
        if (!str_contains($script, "state['state'] = 'idle'")) {
            throw new \RuntimeException('Unsupported in-memory Redis script');
        }
        [$connectionKey, $boundKey, $idleKey] = array_slice($arguments, 0, $keyCount);
        [$validationId, $sessionId, $requestId, $now, $ttl, $clientId] = array_slice($arguments, $keyCount);
        $raw = $this->get($connectionKey);
        if (!is_string($raw)) {
            return false;
        }
        $state = json_decode($raw, true);
        if (($state['state'] ?? '') !== 'validating'
            || ($state['validation_id'] ?? '') !== $validationId
            || ($state['session_id'] ?? '') !== $sessionId
            || ($state['request_id'] ?? '') !== $requestId) {
            return false;
        }
        $state['state'] = 'idle';
        $state['account_id'] = 0;
        $state['validation_id'] = '';
        $state['session_id'] = '';
        $state['request_id'] = '';
        $state['bound_at'] = 0;
        $state['last_seen'] = (int)$now;
        $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->setEx($connectionKey, (int)$ttl, $encoded);
        $this->sRem($boundKey, $clientId);
        $this->sAdd($idleKey, $clientId);
        return $encoded;
    }
}
