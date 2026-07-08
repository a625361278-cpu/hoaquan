<?php

namespace app\repository;

interface GameAccountRepositoryInterface
{
    public function listByUserId(int $userId): array;

    public function findByUserId(int $userId, int $accountId): ?array;

    public function findById(int $accountId): ?array;

    public function listByStatuses(array $statuses): array;

    public function listAutoRestartCandidates(array $statuses, string $now, int $limit): array;

    public function listDesiredRunningAccounts(array $statuses, int $afterId, int $limit): array;

    public function createLocalPreview(int $userId, array $data): array;

    public function saveLocalConfig(int $userId, int $accountId, array $config, string $syncStatus): array;

    public function updateCredentials(int $userId, int $accountId, string $encryptedPassword): array;

    public function updateRuntimeState(int $userId, int $accountId, array $data): array;

    public function deleteForUser(int $userId, int $accountId): void;

    public function appendLogLines(int $accountId, array $lines, int $maxLines): void;

    public function appendNormalLogLines(int $accountId, string $sessionId, array $lines, int $maxLines): void;

    public function listLogLines(int $accountId, int $afterLine, int $limit): array;

    public function listNormalLogLines(int $accountId, string $sessionId, int $afterLine, int $limit): array;

    public function countLogLines(int $accountId): int;

    public function countNormalLogLines(int $accountId, string $sessionId): int;

    public function clearLogLines(int $accountId): void;

    public function clearNormalLogLines(int $accountId, ?string $sessionId = null): void;

    public function appendEventLogs(int $accountId, array $events, int $maxEvents): void;

    public function listEventLogs(int $accountId, int $afterEventNo, int $limit): array;

    public function countEventLogs(int $accountId): int;

    public function clearEventLogs(int $accountId): void;

    public function taskState(int $accountId): ?array;

    public function saveTaskState(int $accountId, string $stateJson, string $stateHash, int $stateBytes, string $savedAt): array;

    public function saveTaskStates(array $states): array;

    public function deleteTaskState(int $accountId): void;
}
