<?php

namespace app\service;

interface GameAccountLoginValidationStoreInterface
{
    public function begin(array $job): array;

    public function activate(string $validationId, string $clientId): array;

    public function abortStart(string $validationId): void;

    public function forget(string $validationId): void;

    public function getForUser(int $userId, string $validationId): ?array;

    public function claimResponse(string $validationId, string $requestId, string $sessionId): ?array;

    public function claimTimeout(string $validationId): ?array;

    public function complete(string $validationId, string $status, string $message, int $accountId = 0, string $serverName = ''): array;

    public function failPending(string $validationId, string $message): ?array;

    public function dueValidationIds(int $now, int $limit): array;
}
