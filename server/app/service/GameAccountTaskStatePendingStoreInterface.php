<?php

namespace app\service;

interface GameAccountTaskStatePendingStoreInterface
{
    public function save(int $accountId, string $stateJson, string $stateHash, int $stateBytes, string $queuedAt): array;

    public function get(int $accountId): ?array;

    public function clearIfHashMatches(int $accountId, string $stateHash): void;

    public function clear(int $accountId): void;
}
