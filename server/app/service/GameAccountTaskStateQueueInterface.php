<?php

namespace app\service;

interface GameAccountTaskStateQueueInterface
{
    public function enqueue(int $accountId, string $stateJson, string $stateHash, int $stateBytes, string $queuedAt): void;

    public function popFromShard(int $shard, int $limit): array;

    public function stats(): array;

    public function recordWriterHeartbeat(int $workerId, array $stats): void;
}
