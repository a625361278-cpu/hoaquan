<?php

namespace tests\Support;

use app\service\ThirdPartyScriptConnectionStoreInterface;

class ArrayThirdPartyScriptConnectionStore implements ThirdPartyScriptConnectionStoreInterface
{
    public array $connections = [];
    public array $accounts = [];

    public function registerIdle(string $clientId, array $metadata = []): array
    {
        $state = [
            'client_id' => $clientId,
            'state' => 'idle',
            'account_id' => 0,
            'session_id' => '',
            'request_id' => '',
            'validation_id' => '',
            'connected_at' => 1783123200,
            'last_seen' => 1783123200,
        ] + $metadata;
        $this->connections[$clientId] = $state;
        return $state;
    }

    public function heartbeat(string $clientId, array $metadata = []): ?array
    {
        if (!isset($this->connections[$clientId])) {
            return null;
        }
        $this->connections[$clientId]['last_seen'] = 1783123210;
        return $this->connections[$clientId];
    }

    public function connection(string $clientId): ?array
    {
        return $this->connections[$clientId] ?? null;
    }

    public function connectionByAccount(int $accountId): ?array
    {
        $clientId = $this->accounts[$accountId] ?? null;
        return $clientId ? ($this->connections[$clientId] ?? null) : null;
    }

    public function allocateIdle(int $accountId, string $sessionId, string $requestId): ?array
    {
        foreach ($this->connections as $clientId => $state) {
            if (($state['state'] ?? '') !== 'idle') {
                continue;
            }
            $state['state'] = 'bound';
            $state['account_id'] = $accountId;
            $state['session_id'] = $sessionId;
            $state['request_id'] = $requestId;
            $this->connections[$clientId] = $state;
            $this->accounts[$accountId] = $clientId;
            return $state;
        }
        return null;
    }

    public function markStopping(int $accountId): ?array
    {
        $state = $this->connectionByAccount($accountId);
        if (!$state) {
            return null;
        }
        return $this->markClientStopping((string)$state['client_id']);
    }

    public function markClientStopping(string $clientId): ?array
    {
        $state = $this->connections[$clientId] ?? null;
        if (!$state || (int)($state['account_id'] ?? 0) <= 0) {
            return null;
        }
        $state['state'] = 'stopping';
        $this->connections[$clientId] = $state;
        return $state;
    }

    public function allocateIdleForValidation(string $validationId, string $sessionId, string $requestId): ?array
    {
        foreach ($this->connections as $clientId => $state) {
            if (($state['state'] ?? '') !== 'idle') {
                continue;
            }
            $state['state'] = 'validating';
            $state['validation_id'] = $validationId;
            $state['session_id'] = $sessionId;
            $state['request_id'] = $requestId;
            $this->connections[$clientId] = $state;
            return $state;
        }
        return null;
    }

    public function restoreValidationToIdle(string $clientId, string $validationId, string $sessionId, string $requestId): ?array
    {
        $state = $this->connections[$clientId] ?? null;
        if (!$state
            || ($state['state'] ?? '') !== 'validating'
            || ($state['validation_id'] ?? '') !== $validationId
            || ($state['session_id'] ?? '') !== $sessionId
            || ($state['request_id'] ?? '') !== $requestId) {
            return null;
        }
        $state['state'] = 'idle';
        $state['validation_id'] = '';
        $state['session_id'] = '';
        $state['request_id'] = '';
        $this->connections[$clientId] = $state;
        return $state;
    }

    public function releaseClient(string $clientId): ?array
    {
        $state = $this->connections[$clientId] ?? null;
        unset($this->connections[$clientId]);
        if ($state && (int)($state['account_id'] ?? 0) > 0) {
            $accountId = (int)$state['account_id'];
            if (($this->accounts[$accountId] ?? null) === $clientId) {
                unset($this->accounts[$accountId]);
            }
        }
        return $state;
    }

    public function listConnections(): array
    {
        return array_values($this->connections);
    }

    public function stats(): array
    {
        $rows = $this->listConnections();
        return [
            'online_count' => count($rows),
            'idle_count' => count(array_filter($rows, static fn (array $row): bool => ($row['state'] ?? '') === 'idle')),
            'bound_count' => count(array_filter($rows, static fn (array $row): bool => ($row['state'] ?? '') === 'bound')),
            'stopping_count' => count(array_filter($rows, static fn (array $row): bool => ($row['state'] ?? '') === 'stopping')),
            'validating_count' => count(array_filter($rows, static fn (array $row): bool => ($row['state'] ?? '') === 'validating')),
        ];
    }
}
