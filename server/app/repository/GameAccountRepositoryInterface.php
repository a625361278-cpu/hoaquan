<?php

namespace app\repository;

interface GameAccountRepositoryInterface
{
    public function listByUserId(int $userId): array;

    public function findByUserId(int $userId, int $accountId): ?array;

    public function findById(int $accountId): ?array;

    public function listByStatuses(array $statuses): array;

    public function createLocalPreview(int $userId, array $data): array;

    public function saveLocalConfig(int $userId, int $accountId, array $config, string $syncStatus): array;

    public function updateCredentials(int $userId, int $accountId, string $encryptedPassword): array;

    public function updateRuntimeState(int $userId, int $accountId, array $data): array;

    public function deleteForUser(int $userId, int $accountId): void;

    public function appendLogLines(int $accountId, array $lines, int $maxLines): void;

    public function listLogLines(int $accountId, int $afterLine, int $limit): array;

    public function countLogLines(int $accountId): int;

    public function clearLogLines(int $accountId): void;
}
