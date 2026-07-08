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
}
