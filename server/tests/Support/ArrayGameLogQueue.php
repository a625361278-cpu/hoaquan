<?php

namespace tests\Support;

use app\service\GameLogQueue;
use app\service\GameLogQueueInterface;

class ArrayGameLogQueue implements GameLogQueueInterface
{
    public array $normal = [];
    public array $events = [];
    public array $queues = [];
    public array $writerHeartbeats = [];

    public function enqueueNormal(int $accountId, array $lines, string $sessionId = ''): void
    {
        $this->normal[] = [
            'account_id' => $accountId,
            'lines' => array_values($lines),
            'session_id' => $sessionId,
        ];
        $this->enqueue($accountId, [
            'type' => 'normal',
            'account_id' => $accountId,
            'session_id' => $sessionId,
            'lines' => array_values($lines),
            'created_at' => 1783428000,
        ]);
    }

    public function enqueueEvents(int $accountId, array $events): void
    {
        $this->events[] = [
            'account_id' => $accountId,
            'events' => array_values($events),
        ];
        $this->enqueue($accountId, [
            'type' => 'event',
            'account_id' => $accountId,
            'events' => array_values($events),
            'created_at' => 1783428000,
        ]);
    }

    public function popFromShard(int $shard, int $limit): array
    {
        $records = [];
        $limit = max(1, $limit);
        for ($i = 0; $i < $limit; $i++) {
            if (empty($this->queues[$shard])) {
                break;
            }
            $records[] = array_shift($this->queues[$shard]);
        }
        return $records;
    }

    public function stats(): array
    {
        $totalPending = 0;
        $maxShardPending = 0;
        $maxShard = 0;
        $pendingByShard = [];
        for ($shard = 0; $shard < GameLogQueue::SHARD_COUNT; $shard++) {
            $pending = count($this->queues[$shard] ?? []);
            $pendingByShard[$shard] = $pending;
            $totalPending += $pending;
            if ($pending > $maxShardPending) {
                $maxShardPending = $pending;
                $maxShard = $shard;
            }
        }

        return [
            'shard_count' => GameLogQueue::SHARD_COUNT,
            'total_pending' => $totalPending,
            'max_shard_pending' => $maxShardPending,
            'max_shard' => $maxShard,
            'pending_by_shard' => $pendingByShard,
            'writer_count' => count($this->writerHeartbeats),
            'last_flush_at' => 0,
            'last_flush_at_text' => '',
            'last_error' => '',
        ];
    }

    public function recordWriterHeartbeat(int $workerId, array $stats): void
    {
        $this->writerHeartbeats[$workerId] = $stats;
    }

    private function enqueue(int $accountId, array $record): void
    {
        $shard = GameLogQueue::shardForAccount($accountId);
        $this->queues[$shard] ??= [];
        $this->queues[$shard][] = $record;
    }
}
