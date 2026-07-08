<?php

namespace tests\Support;

use app\service\GameAccountTaskStateQueue;
use app\service\GameAccountTaskStateQueueInterface;

class ArrayGameAccountTaskStateQueue implements GameAccountTaskStateQueueInterface
{
    public array $queues = [];
    public array $writerStats = [];

    public function enqueue(int $accountId, string $stateJson, string $stateHash, int $stateBytes, string $queuedAt): void
    {
        $shard = GameAccountTaskStateQueue::shardForAccount($accountId);
        $this->queues[$shard][] = [
            'account_id' => $accountId,
            'state_json' => $stateJson,
            'state_hash' => $stateHash,
            'state_bytes' => $stateBytes,
            'queued_at' => $queuedAt,
        ];
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
        $totalPending = 0;
        foreach ($this->queues as $records) {
            $totalPending += count($records);
        }
        return [
            'shard_count' => GameAccountTaskStateQueue::SHARD_COUNT,
            'total_pending' => $totalPending,
            'writer_count' => count($this->writerStats),
        ];
    }

    public function recordWriterHeartbeat(int $workerId, array $stats): void
    {
        $this->writerStats[$workerId] = $stats;
    }
}
