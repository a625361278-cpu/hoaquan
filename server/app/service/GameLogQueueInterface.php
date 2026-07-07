<?php

namespace app\service;

interface GameLogQueueInterface extends GameLogSinkInterface
{
    public function popFromShard(int $shard, int $limit): array;

    public function stats(): array;

    public function recordWriterHeartbeat(int $workerId, array $stats): void;
}
