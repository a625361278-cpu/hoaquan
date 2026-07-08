<?php

namespace app\service;

interface GameAccountRuntimeResourceStoreInterface
{
    public function get(int $accountId): ?array;

    public function save(int $accountId, array $resources): array;

    public function clear(int $accountId): void;
}
