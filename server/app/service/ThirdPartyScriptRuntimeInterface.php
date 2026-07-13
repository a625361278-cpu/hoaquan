<?php

namespace app\service;

interface ThirdPartyScriptRuntimeInterface
{
    public function reserveAccount(int $accountId, string $requestId, string $sessionId): array;

    public function reserveValidation(string $validationId, string $requestId, string $sessionId): array;

    public function sendStartCommand(array $reservation, array $account, string $credential, array $config, array $taskState = []): array;

    public function sendLoginValidationCommand(array $reservation, int $loginMethod, string $identity, string $credential): array;

    public function restoreValidationConnection(array $reservation): bool;

    public function discardValidationConnection(array $reservation): void;

    public function releaseReservation(array $reservation): void;

    public function startAccount(array $account, string $requestId, string $sessionId, string $credential, array $config, array $taskState = []): array;

    public function stopAccount(int $accountId, string $requestId): array;
}
