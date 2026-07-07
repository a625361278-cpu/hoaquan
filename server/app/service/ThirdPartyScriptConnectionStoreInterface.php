<?php

namespace app\service;

interface ThirdPartyScriptConnectionStoreInterface
{
    public function registerIdle(string $clientId, array $metadata = []): array;

    public function heartbeat(string $clientId, array $metadata = []): ?array;

    public function connection(string $clientId): ?array;

    public function connectionByAccount(int $accountId): ?array;

    public function allocateIdle(int $accountId, string $sessionId, string $requestId): ?array;

    public function markStopping(int $accountId): ?array;

    public function releaseClient(string $clientId): ?array;

    public function listConnections(): array;

    public function stats(): array;
}
