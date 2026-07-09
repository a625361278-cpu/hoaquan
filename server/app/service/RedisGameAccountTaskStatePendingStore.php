<?php

namespace app\service;

use JsonException;
use RuntimeException;
use support\Redis;

class RedisGameAccountTaskStatePendingStore implements GameAccountTaskStatePendingStoreInterface
{
    private const PREFIX = 'gameassist:game_task_states:pending:';

    public function save(int $accountId, string $stateJson, string $stateHash, int $stateBytes, string $queuedAt): array
    {
        $snapshot = [
            'game_account_id' => $accountId,
            'state_json' => $stateJson,
            'state_hash' => $stateHash,
            'state_bytes' => $stateBytes,
            'saved_at' => $queuedAt,
        ];
        try {
            $payload = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('待落库任务状态快照编码失败: ' . $e->getMessage(), 0, $e);
        }

        if (Redis::set($this->key($accountId), $payload) === false) {
            throw new RuntimeException('待落库任务状态快照写入失败');
        }
        return $snapshot;
    }

    public function get(int $accountId): ?array
    {
        $payload = Redis::get($this->key($accountId));
        if ($payload === false || $payload === null || $payload === '') {
            return null;
        }

        $snapshot = json_decode((string)$payload, true);
        if (!is_array($snapshot)) {
            throw new RuntimeException('待落库任务状态快照异常');
        }

        return $snapshot;
    }

    public function clearIfHashMatches(int $accountId, string $stateHash): void
    {
        $snapshot = $this->get($accountId);
        if (!$snapshot || !hash_equals((string)($snapshot['state_hash'] ?? ''), $stateHash)) {
            return;
        }
        $this->clear($accountId);
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
