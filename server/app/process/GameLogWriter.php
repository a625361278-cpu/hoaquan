<?php

namespace app\process;

use app\repository\DbGameAccountRepository;
use app\repository\GameAccountRepositoryInterface;
use app\service\GameAccountLogService;
use app\service\GameLogNormalizer;
use app\service\GameLogQueue;
use app\support\I18n;
use support\Log;
use Throwable;
use Workerman\Timer;

class GameLogWriter
{
    private const POLL_INTERVAL = 0.2;
    private const HEARTBEAT_INTERVAL = 1;
    private const MAX_RECORDS_PER_TICK = 500;
    private const MAX_RECORDS_PER_SHARD = 100;
    private const NORMAL_FLUSH_SECONDS = 10;
    private const NORMAL_FLUSH_LINES = 50;
    private const EVENT_FLUSH_SECONDS = 2;
    private const EVENT_FLUSH_EVENTS = 20;

    private int $workerId = 0;
    private int $workerCount = 1;
    private array $ownedShards = [];
    private array $normalBuffers = [];
    private array $eventBuffers = [];
    private int $lastFlushAt = 0;
    private string $lastError = '';
    private int $processedRecords = 0;

    public function __construct(
        private ?GameLogQueue $queue = null,
        private ?GameAccountRepositoryInterface $accounts = null,
        private string $locale = I18n::DEFAULT_LOCALE,
        private ?GameLogNormalizer $normalizer = null
    )
    {
        $this->queue ??= new GameLogQueue();
        $this->accounts ??= new DbGameAccountRepository();
        $this->locale = I18n::normalizeLocale($this->locale);
        $this->normalizer ??= new GameLogNormalizer();
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
        for ($shard = 0; $shard < GameLogQueue::SHARD_COUNT; $shard++) {
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
        $this->flushReadyBuffers();
    }

    public function flushDueBuffers(bool $force): void
    {
        $now = time();
        foreach (array_keys($this->normalBuffers) as $key) {
            $buffer = $this->normalBuffers[$key];
            if ($force || $now - (int)$buffer['first_at'] >= self::NORMAL_FLUSH_SECONDS) {
                $this->flushNormalBuffer($key);
            }
        }

        foreach (array_keys($this->eventBuffers) as $key) {
            $buffer = $this->eventBuffers[$key];
            if ($force || $now - (int)$buffer['first_at'] >= self::EVENT_FLUSH_SECONDS) {
                $this->flushEventBuffer($key);
            }
        }
    }

    private function aggregate(array $record): void
    {
        try {
            $accountId = (int)($record['account_id'] ?? 0);
            if ($accountId <= 0) {
                throw new \RuntimeException('日志队列记录缺少游戏账号ID');
            }

            $type = (string)($record['type'] ?? 'normal');
            if ($type === 'event') {
                $events = $record['events'] ?? [];
                if (!is_array($events)) {
                    throw new \RuntimeException('事件日志格式错误');
                }
                $this->bufferEvents($accountId, $this->normalizer->normalizeEvents($events));
                return;
            }

            $lines = $record['lines'] ?? [];
            if (!is_array($lines)) {
                throw new \RuntimeException('普通日志格式错误');
            }
            $sessionId = (string)($record['session_id'] ?? '');
            if ($sessionId === '') {
                throw new \RuntimeException('普通日志缺少运行会话ID');
            }

            $normalized = $this->normalizer->normalizeLines($lines);
            if ($normalized === []) {
                return;
            }
            $this->bufferNormalLines($accountId, $sessionId, $normalized);
            $this->bufferEvents($accountId, $this->normalizer->eventsFromLines($normalized));
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error('Failed to aggregate game log queue record', [
                'worker_id' => $this->workerId,
                'record' => $record,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function bufferNormalLines(int $accountId, string $sessionId, array $lines): void
    {
        $key = $accountId . ':' . $sessionId;
        if (!isset($this->normalBuffers[$key])) {
            $this->normalBuffers[$key] = [
                'account_id' => $accountId,
                'session_id' => $sessionId,
                'lines' => [],
                'first_at' => time(),
            ];
        }
        foreach ($lines as $line) {
            $this->normalBuffers[$key]['lines'][] = $line;
        }
        if (count($this->normalBuffers[$key]['lines']) >= self::NORMAL_FLUSH_LINES) {
            $this->flushNormalBuffer($key);
        }
    }

    private function bufferEvents(int $accountId, array $events): void
    {
        if ($events === []) {
            return;
        }

        $key = (string)$accountId;
        if (!isset($this->eventBuffers[$key])) {
            $this->eventBuffers[$key] = [
                'account_id' => $accountId,
                'events' => [],
                'first_at' => time(),
            ];
        }
        foreach ($events as $event) {
            $this->eventBuffers[$key]['events'][] = $event;
        }
        if (count($this->eventBuffers[$key]['events']) >= self::EVENT_FLUSH_EVENTS) {
            $this->flushEventBuffer($key);
        }
    }

    private function flushReadyBuffers(): void
    {
        foreach (array_keys($this->normalBuffers) as $key) {
            if (count($this->normalBuffers[$key]['lines']) >= self::NORMAL_FLUSH_LINES) {
                $this->flushNormalBuffer($key);
            }
        }
        foreach (array_keys($this->eventBuffers) as $key) {
            if (count($this->eventBuffers[$key]['events']) >= self::EVENT_FLUSH_EVENTS) {
                $this->flushEventBuffer($key);
            }
        }
    }

    private function flushNormalBuffer(string $key): void
    {
        if (!isset($this->normalBuffers[$key])) {
            return;
        }

        $buffer = $this->normalBuffers[$key];
        if (($buffer['lines'] ?? []) === []) {
            unset($this->normalBuffers[$key]);
            return;
        }

        try {
            $this->accounts->appendNormalLogLines(
                (int)$buffer['account_id'],
                (string)$buffer['session_id'],
                $buffer['lines'],
                GameAccountLogService::MAX_LINES_PER_ACCOUNT
            );
            unset($this->normalBuffers[$key]);
            $this->lastFlushAt = time();
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error('Failed to flush normal game log buffer', [
                'worker_id' => $this->workerId,
                'account_id' => (int)$buffer['account_id'],
                'session_id' => (string)$buffer['session_id'],
                'line_count' => count($buffer['lines']),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function flushEventBuffer(string $key): void
    {
        if (!isset($this->eventBuffers[$key])) {
            return;
        }

        $buffer = $this->eventBuffers[$key];
        if (($buffer['events'] ?? []) === []) {
            unset($this->eventBuffers[$key]);
            return;
        }

        try {
            $this->accounts->appendEventLogs(
                (int)$buffer['account_id'],
                $buffer['events'],
                GameAccountLogService::MAX_EVENTS_PER_ACCOUNT
            );
            unset($this->eventBuffers[$key]);
            $this->lastFlushAt = time();
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error('Failed to flush event game log buffer', [
                'worker_id' => $this->workerId,
                'account_id' => (int)$buffer['account_id'],
                'event_count' => count($buffer['events']),
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
                'normal_buffer_count' => count($this->normalBuffers),
                'event_buffer_count' => count($this->eventBuffers),
                'processed_records' => $this->processedRecords,
                'last_flush_at' => $this->lastFlushAt,
                'last_error' => $this->lastError,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to update game log writer heartbeat', [
                'worker_id' => $this->workerId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
