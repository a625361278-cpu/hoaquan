<?php

namespace tests\Support;

use app\service\GameLogQueue;

class ArrayGameLogQueue extends GameLogQueue
{
    public array $heartbeats = [];
    private array $queues = [];

    public function enqueueNormal(int $accountId, array $lines, string $sessionId = ''): void
    {
        $this->push($accountId, [
            'type' => 'normal',
            'account_id' => $accountId,
            'session_id' => $sessionId,
            'lines' => array_values($lines),
            'created_at' => time(),
        ]);
    }

    public function enqueueEvents(int $accountId, array $events): void
    {
        $this->push($accountId, [
            'type' => 'event',
            'account_id' => $accountId,
            'events' => array_values($events),
            'created_at' => time(),
        ]);
    }

    public function popFromShard(int $shard, int $limit): array
    {
        $records = [];
        $limit = max(1, $limit);
        for ($i = 0; $i < $limit; $i++) {
            if (($this->queues[$shard] ?? []) === []) {
                break;
            }
            $records[] = array_shift($this->queues[$shard]);
        }
        return $records;
    }

    public function stats(): array
    {
        $total = 0;
        $maxPending = 0;
        $maxShard = 0;
        for ($shard = 0; $shard < self::SHARD_COUNT; $shard++) {
            $pending = count($this->queues[$shard] ?? []);
            $total += $pending;
            if ($pending > $maxPending) {
                $maxPending = $pending;
                $maxShard = $shard;
            }
        }
        return [
            'shard_count' => self::SHARD_COUNT,
            'total_pending' => $total,
            'max_shard_pending' => $maxPending,
            'max_shard' => $maxShard,
            'writer_count' => count($this->heartbeats),
            'last_flush_at' => 0,
            'last_flush_at_text' => '',
            'last_error' => '',
        ];
    }

    public function recordWriterHeartbeat(int $workerId, array $stats): void
    {
        $this->heartbeats[$workerId] = $stats;
    }

    private function push(int $accountId, array $record): void
    {
        $shard = self::shardForAccount($accountId);
        $this->queues[$shard] ??= [];
        $this->queues[$shard][] = $record;
    }
}
