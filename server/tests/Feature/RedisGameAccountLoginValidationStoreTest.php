<?php

namespace tests\Feature;

use app\service\RedisGameAccountLoginValidationStore;
use PHPUnit\Framework\TestCase;

class RedisGameAccountLoginValidationStoreTest extends TestCase
{
    public function testBeginUsesIlluminateRedisEvalSignature(): void
    {
        $redis = new IlluminateEvalSignatureRedisClient();
        $store = new RedisGameAccountLoginValidationStore($redis);
        $job = [
            'validation_id' => str_repeat('a', 32),
            'user_id' => 7,
            'status' => 'reserving',
            'request_id' => str_repeat('b', 32),
            'session_id' => str_repeat('c', 24),
            'fingerprint' => str_repeat('d', 64),
            'expires_at' => time() + 20,
        ];

        $result = $store->begin($job);

        $this->assertSame('created', $result['kind']);
        $this->assertSame($job['validation_id'], $result['job']['validation_id']);
        $this->assertSame(4, $redis->keyCount);
        $this->assertCount(10, $redis->arguments);
        $this->assertSame(
            RedisGameAccountLoginValidationStore::PREFIX . 'jobs:' . $job['validation_id'],
            $redis->arguments[0]
        );
    }
}

class IlluminateEvalSignatureRedisClient
{
    public int $keyCount = 0;
    public array $arguments = [];

    public function eval(string $script, int $keyCount, mixed ...$arguments): array
    {
        $this->keyCount = $keyCount;
        $this->arguments = $arguments;

        return ['created', (string)$arguments[5]];
    }
}
