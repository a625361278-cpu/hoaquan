<?php

namespace app\service;

interface TokenStoreInterface
{
    public function create(int $userId): string;

    public function getUserId(string $token): ?int;

    public function delete(string $token): void;
}
