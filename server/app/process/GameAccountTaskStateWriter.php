<?php

namespace app\process;

use app\repository\DbGameAccountRepository;
use app\repository\GameAccountRepositoryInterface;
use app\service\GameAccountTaskStateQueue;
use app\service\GameAccountTaskStateQueueInterface;
use app\service\GameAccountTaskStateService;
use support\Log;
use Throwable;
use Workerman\Timer;

class GameAccountTaskStateWriter
{
    private const POLL_INTERVAL = 0.2;
    private const HEARTBEAT_INTERVAL = 1;
    private const MAX_RECORDS_PER_TICK = 1000;
    private const MAX_RECORDS_PER_SHARD = 200;
    private const FLUSH_SECONDS = 1;
    private const FLUSH_ACCOUNTS = 500;

    private int $workerId = 0;
    private int $workerCount = 1;
    private array $ownedShards = [];
    private array $buffers = [];
    private int $lastFlushAt = 0;
    private string $lastError = '';
    private int $processedRecords = 0;

    public function __construct(
        private ?GameAccountTaskStateQueueInterface $queue = null,
        private ?GameAccountRepositoryInterface $accounts = null,
        private ?GameAccountTaskStateService $taskStates = null
    ) {
        $this->queue ??= new GameAccountTaskStateQueue();
        $this->accounts ??= new DbGameAccountRepository();
        $this->taskStates ??= new GameAccountTaskStateService($this->accounts);
        $this->setWorkerIdentity(0, 1);
    }

    public function onWorkerStart($worker = null): void
    {
        $this->setWorkerIdentity((int)($worker->id ?? 0), max(1, (int)($worker->count ?? 1)));
        Timer::add(self::POLL_INTERVAL, fn () => $this->drainQueues());
        Timer::add(self::HEARTBEAT_INTERVAL, fn () => $this->flushDueBuffers(false));
        Timer::add(self::HEARTBEAT_INTERVAL, fn () => $this->heartbeat());
    }

    public function onWorkerStop(): void
    {
        $this->flushDueBuffers(true);
        $this->heartbeat();
    }

    public function setWorkerIdentity(int $workerId, int $workerCount): void
    {
        $this->workerId = max(0, $workerId);
        $this->workerCount = max(1, $workerCount);
        $this->ownedShards = [];
        for ($shard = 0; $shard < GameAccountTaskStateQueue::SHARD_COUNT; $shard++) {
            if ($shard % $this->workerCount === $this->workerId) {
                $this->ownedShards[] = $shard;
            }
        }
    }

    public function drainQueues(): void
    {
        $remaining = self::MAX_RECORDS_PER_TICK;
        foreach ($this->ownedShards as $shard) {
            if ($remaining <= 0) {
                break;
            }
            $records = $this->queue->popFromShard($shard, min(self::MAX_RECORDS_PER_SHARD, $remaining));
            foreach ($records as $record) {
                $this->aggregate($record);
                $remaining--;
                $this->processedRecords++;
                if ($remaining <= 0) {
                    break;
                }
            }
        }

        if (count($this->buffers) >= self::FLUSH_ACCOUNTS) {
            $this->flushBuffers();
        }
    }

    public function flushDueBuffers(bool $force): void
    {
        if ($this->buffers === []) {
            return;
        }

        $oldest = min(array_map(static fn (array $buffer): int => (int)$buffer['first_at'], $this->buffers));
        if ($force || time() - $oldest >= self::FLUSH_SECONDS) {
            $this->flushBuffers();
        }
    }

    private function aggregate(array $record): void
    {
        try {
            $accountId = (int)($record['account_id'] ?? 0);
            if ($accountId <= 0) {
                throw new \RuntimeException('任务状态队列记录缺少游戏账号ID');
            }
            $stateJson = (string)($record['state_json'] ?? '');
            $stateHash = (string)($record['state_hash'] ?? '');
            $stateBytes = (int)($record['state_bytes'] ?? 0);
            if ($stateJson === '' || $stateHash === '' || $stateBytes <= 0) {
                throw new \RuntimeException('任务状态队列记录格式错误');
            }

            $firstAt = $this->buffers[$accountId]['first_at'] ?? time();
            $this->buffers[$accountId] = [
                'game_account_id' => $accountId,
                'state_json' => $stateJson,
                'state_hash' => $stateHash,
                'state_bytes' => $stateBytes,
                'saved_at' => (string)($record['queued_at'] ?? date('Y-m-d H:i:s')),
                'first_at' => $firstAt,
            ];
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error('Failed to aggregate game task state queue record', [
                'worker_id' => $this->workerId,
                'record' => $record,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function flushBuffers(): void
    {
        if ($this->buffers === []) {
            return;
        }

        $buffers = $this->buffers;
        try {
            $result = $this->accounts->saveTaskStates(array_values($buffers));
            foreach ($buffers as $buffer) {
                $this->taskStates->clearPendingIfHashMatches(
                    (int)$buffer['game_account_id'],
                    (string)$buffer['state_hash']
                );
            }
            $this->buffers = [];
            $this->lastFlushAt = time();
            if ((int)($result['missing'] ?? 0) > 0) {
                $this->lastError = 'skipped missing accounts: ' . (int)$result['missing'];
            }
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error('Failed to flush game task state buffer', [
                'worker_id' => $this->workerId,
                'account_count' => count($buffers),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function heartbeat(): void
    {
        try {
            $this->queue->recordWriterHeartbeat($this->workerId, [
                'worker_count' => $this->workerCount,
                'owned_shards' => $this->ownedShards,
                'buffer_count' => count($this->buffers),
                'processed_records' => $this->processedRecords,
                'last_flush_at' => $this->lastFlushAt,
                'last_error' => $this->lastError,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to update game task state writer heartbeat', [
                'worker_id' => $this->workerId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
