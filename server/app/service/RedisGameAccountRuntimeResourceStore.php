<?php

namespace app\service;

use RuntimeException;
use support\Redis;

class RedisGameAccountRuntimeResourceStore implements GameAccountRuntimeResourceStoreInterface
{
    private const PREFIX = 'gameassist:game_account_resources:';

    public function get(int $accountId): ?array
    {
        $payload = Redis::get($this->key($accountId));
        if (!$payload) {
            return null;
        }

        $decoded = json_decode((string)$payload, true);
        if (!is_array($decoded) || !isset($decoded['resources']) || !is_array($decoded['resources'])) {
            throw new RuntimeException('游戏账号运行资源快照异常');
        }

        return [
            'resources' => $decoded['resources'],
            'updated_at' => (string)($decoded['updated_at'] ?? ''),
        ];
    }

    public function save(int $accountId, array $resources): array
    {
        $saved = [
            'resources' => $resources,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        Redis::set($this->key($accountId), json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $saved;
    }

    public function clear(int $accountId): void
    {
        Redis::del($this->key($accountId));
    }

    private function key(int $accountId): string
    {
        if ($accountId <= 0) {
            throw new RuntimeException('游戏账号 ID 异常');
        }
        return self::PREFIX . $accountId;
    }
}
