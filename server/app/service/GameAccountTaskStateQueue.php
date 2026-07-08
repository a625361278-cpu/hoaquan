<?php

namespace app\service;

use support\Redis;

class GameAccountTaskStateQueue implements GameAccountTaskStateQueueInterface
{
    public const SHARD_COUNT = 64;
    public const QUEUE_PREFIX = 'gameassist:game_task_states:queue:';
    public const WRITER_STATS_KEY = 'gameassist:game_task_states:writer_stats';
    private const WRITER_STATS_TTL = 30;

    public function enqueue(int $accountId, string $stateJson, string $stateHash, int $stateBytes, string $queuedAt): void
    {
        Redis::rPush(
            $this->queueKey(self::shardForAccount($accountId)),
            json_encode([
                'account_id' => $accountId,
                'state_json' => $stateJson,
                'state_hash' => $stateHash,
                'state_bytes' => $stateBytes,
                'queued_at' => $queuedAt,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function popFromShard(int $shard, int $limit): array
    {
        $records = [];
        $limit = max(1, $limit);
        for ($i = 0; $i < $limit; $i++) {
            $payload = Redis::lPop($this->queueKey($shard));
            if (!$payload) {
                break;
            }
            $record = json_decode((string)$payload, true);
            if (is_array($record)) {
                $records[] = $record;
            }
        }
        return $records;
    }

    public function stats(): array
    {
        $totalPending = 0;
        $maxShardPending = 0;
        $maxShard = 0;
        $pendingByShard = [];
        for ($shard = 0; $shard < self::SHARD_COUNT; $shard++) {
            $pending = (int)Redis::lLen($this->queueKey($shard));
            $pendingByShard[$shard] = $pending;
            $totalPending += $pending;
            if ($pending > $maxShardPending) {
                $maxShardPending = $pending;
                $maxShard = $shard;
            }
        }

        $writerStats = $this->writerStats();
        $lastFlushAt = 0;
        $lastError = '';
        foreach ($writerStats as $stat) {
            $lastFlushAt = max($lastFlushAt, (int)($stat['last_flush_at'] ?? 0));
            $error = trim((string)($stat['last_error'] ?? ''));
            if ($error !== '') {
                $lastError = $error;
            }
        }

        return [
            'shard_count' => self::SHARD_COUNT,
            'total_pending' => $totalPending,
            'max_shard_pending' => $maxShardPending,
            'max_shard' => $maxShard,
            'pending_by_shard' => $pendingByShard,
            'writer_count' => count($writerStats),
            'last_flush_at' => $lastFlushAt,
            'last_flush_at_text' => $lastFlushAt > 0 ? date('Y-m-d H:i:s', $lastFlushAt) : '',
            'last_error' => $lastError,
        ];
    }

    public function recordWriterHeartbeat(int $workerId, array $stats): void
    {
        $stats['worker_id'] = $workerId;
        $stats['heartbeat_at'] = time();
        Redis::hSet(self::WRITER_STATS_KEY, (string)$workerId, json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Redis::expire(self::WRITER_STATS_KEY, self::WRITER_STATS_TTL);
    }

    public static function shardForAccount(int $accountId): int
    {
        return abs($accountId) % self::SHARD_COUNT;
    }

    public function queueKey(int $shard): string
    {
        if ($shard < 0 || $shard >= self::SHARD_COUNT) {
            throw new \InvalidArgumentException('任务状态分片编号超出范围');
        }
        return self::QUEUE_PREFIX . $shard;
    }

    private function writerStats(): array
    {
        $rawStats = Redis::hGetAll(self::WRITER_STATS_KEY);
        if (!is_array($rawStats) || $rawStats === []) {
            return [];
        }

        $now = time();
        $stats = [];
        foreach ($rawStats as $workerId => $payload) {
            $decoded = json_decode((string)$payload, true);
            if (!is_array($decoded)) {
                continue;
            }
            if ($now - (int)($decoded['heartbeat_at'] ?? 0) > self::WRITER_STATS_TTL) {
                continue;
            }
            $stats[(int)$workerId] = $decoded;
        }
        ksort($stats);
        return $stats;
    }
}
