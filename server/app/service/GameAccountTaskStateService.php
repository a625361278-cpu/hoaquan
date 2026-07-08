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
        private ?GameAccountTaskStateQueueInterface $queue = null
    ) {
        $this->maxStateBytes ??= max(1, (int)app_env('GAME_TASK_STATE_MAX_BYTES', (string)self::DEFAULT_MAX_STATE_BYTES));
        $this->queue ??= new GameAccountTaskStateQueue();
    }

    public function get(int $accountId): array
    {
        $this->requireAccount($accountId);
        $row = $this->accounts->taskState($accountId);
        if (!$row) {
            return [
                'exists' => false,
                'state' => new stdClass(),
                'saved_at' => null,
                'bytes' => 0,
            ];
        }

        $state = json_decode((string)($row['state_json'] ?? ''));
        if (!$state instanceof stdClass) {
            throw new ApiException('stored task state json is invalid', 500);
        }

        return [
            'exists' => true,
            'state' => $state,
            'saved_at' => $row['saved_at'] ?? null,
            'bytes' => (int)($row['state_bytes'] ?? strlen((string)($row['state_json'] ?? ''))),
        ];
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

        return [
            'queued_at' => $queuedAt,
            'bytes' => $stateBytes,
        ];
    }

    public function persistSnapshots(array $states): array
    {
        return $this->accounts->saveTaskStates($states);
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
}
