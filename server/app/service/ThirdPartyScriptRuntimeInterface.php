<?php

namespace app\service;

interface ThirdPartyScriptRuntimeInterface
{
    public function reserveAccount(int $accountId, string $requestId, string $sessionId): array;

    public function sendStartCommand(array $reservation, array $account, string $gamePassword, array $config, array $taskState = []): array;

    public function releaseReservation(array $reservation): void;

    public function startAccount(array $account, string $requestId, string $sessionId, string $gamePassword, array $config, array $taskState = []): array;

    public function stopAccount(int $accountId, string $requestId): array;
}
