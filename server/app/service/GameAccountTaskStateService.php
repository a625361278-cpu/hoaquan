<?php

namespace app\service;

use app\exception\ApiException;
use app\repository\GameAccountRepositoryInterface;
use JsonException;
use stdClass;

class GameAccountTaskStateService
{
    private const DEFAULT_MAX_STATE_BYTES = 262144;

    public function __construct(
        private GameAccountRepositoryInterface $accounts,
        private ?int $maxStateBytes = null,
        private ?GameAccountTaskStateQueueInterface $queue = null,
        private ?GameAccountTaskStatePendingStoreInterface $pendingStore = null
    ) {
        $this->maxStateBytes ??= max(1, (int)app_env('GAME_TASK_STATE_MAX_BYTES', (string)self::DEFAULT_MAX_STATE_BYTES));
        $this->queue ??= new GameAccountTaskStateQueue();
        $this->pendingStore ??= new RedisGameAccountTaskStatePendingStore();
    }

    public function get(int $accountId): array
    {
        $this->requireAccount($accountId);
        $pending = $this->pendingStore->get($accountId);
        if ($pending) {
            return $this->formatSnapshot($pending, true);
        }

        $row = $this->accounts->taskState($accountId);
        if (!$row) {
            return [
                'exists' => false,
                'state' => new stdClass(),
                'saved_at' => null,
                'bytes' => 0,
            ];
        }

        return $this->formatSnapshot($row, true);
    }

    public function enqueueSave(int $accountId, stdClass $state): array
    {
        $this->requireAccount($accountId);
        $stateJson = $this->encodeState($state);

        $stateBytes = strlen($stateJson);
        if ($stateBytes > $this->maxStateBytes) {
            throw new ApiException('task state too large', 413);
        }

        $stateHash = hash('sha256', $stateJson);
        $queuedAt = date('Y-m-d H:i:s');
        $this->queue->enqueue($accountId, $stateJson, $stateHash, $stateBytes, $queuedAt);
        $this->pendingStore->save($accountId, $stateJson, $stateHash, $stateBytes, $queuedAt);

        return [
            'queued_at' => $queuedAt,
            'bytes' => $stateBytes,
        ];
    }

    public function persistSnapshots(array $states): array
    {
        return $this->accounts->saveTaskStates($states);
    }

    public function clearPendingIfHashMatches(int $accountId, string $stateHash): void
    {
        $this->pendingStore->clearIfHashMatches($accountId, $stateHash);
    }

    public function clearPending(int $accountId): void
    {
        $this->pendingStore->clear($accountId);
    }

    private function requireAccount(int $accountId): array
    {
        $account = $this->accounts->findById($accountId);
        if (!$account) {
            throw new ApiException('game account not found', 404);
        }
        return $account;
    }

    private function encodeState(stdClass $state): string
    {
        try {
            return json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException('task state json encode failed: ' . $e->getMessage(), 422);
        }
    }

    private function formatSnapshot(array $snapshot, bool $exists): array
    {
        $state = json_decode((string)($snapshot['state_json'] ?? ''));
        if (!$state instanceof stdClass) {
            throw new ApiException('stored task state json is invalid', 500);
        }

        return [
            'exists' => $exists,
            'state' => $state,
            'saved_at' => $snapshot['saved_at'] ?? null,
            'bytes' => (int)($snapshot['state_bytes'] ?? strlen((string)($snapshot['state_json'] ?? ''))),
        ];
    }
}
