<?php

namespace tests\Support;

use app\service\GameAccountRuntimeResourceStoreInterface;

class ArrayGameAccountRuntimeResourceStore implements GameAccountRuntimeResourceStoreInterface
{
    public array $snapshots = [];
    public array $cleared = [];

    public function get(int $accountId): ?array
    {
        return $this->snapshots[$accountId] ?? null;
    }

    public function save(int $accountId, array $resources): array
    {
        $snapshot = [
            'resources' => $resources,
            'updated_at' => '2026-07-08 12:00:00',
        ];
        $this->snapshots[$accountId] = $snapshot;
        return $snapshot;
    }

    public function clear(int $accountId): void
    {
        unset($this->snapshots[$accountId]);
        $this->cleared[] = $accountId;
    }
}
