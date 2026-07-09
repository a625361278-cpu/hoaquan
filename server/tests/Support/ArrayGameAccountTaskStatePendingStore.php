<?php

namespace tests\Support;

use app\service\GameAccountTaskStatePendingStoreInterface;

class ArrayGameAccountTaskStatePendingStore implements GameAccountTaskStatePendingStoreInterface
{
    public array $snapshots = [];

    public function save(int $accountId, string $stateJson, string $stateHash, int $stateBytes, string $queuedAt): array
    {
        $this->snapshots[$accountId] = [
            'game_account_id' => $accountId,
            'state_json' => $stateJson,
            'state_hash' => $stateHash,
            'state_bytes' => $stateBytes,
            'saved_at' => $queuedAt,
        ];
        return $this->snapshots[$accountId];
    }

    public function get(int $accountId): ?array
    {
        return $this->snapshots[$accountId] ?? null;
    }

    public function clearIfHashMatches(int $accountId, string $stateHash): void
    {
        if (!isset($this->snapshots[$accountId])) {
            return;
        }
        if (!hash_equals((string)$this->snapshots[$accountId]['state_hash'], $stateHash)) {
            return;
        }
        unset($this->snapshots[$accountId]);
    }

    public function clear(int $accountId): void
    {
        unset($this->snapshots[$accountId]);
    }
}
