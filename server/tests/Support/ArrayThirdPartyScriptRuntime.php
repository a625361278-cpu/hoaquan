<?php

namespace tests\Support;

use app\exception\ApiException;
use app\service\ThirdPartyScriptRuntimeInterface;

class ArrayThirdPartyScriptRuntime implements ThirdPartyScriptRuntimeInterface
{
    public array $started = [];
    public array $stopped = [];
    public array $connections = [];
    public array $released = [];
    public bool $failSend = false;
    public bool $failStopConnection = false;
    public bool $stopSent = true;
    public array $validations = [];
    public array $discardedValidations = [];

    public function __construct(public bool $hasIdleConnection = true)
    {
    }

    public function reserveAccount(int $accountId, string $requestId, string $sessionId): array
    {
        if (!$this->hasIdleConnection) {
            throw new ApiException('服务器未准备好，请联系管理员', 409);
        }

        return [
            'client_id' => 'client-1',
            'request_id' => $requestId,
            'session_id' => $sessionId,
        ];
    }

    public function sendStartCommand(array $reservation, array $account, string $credential, array $config, array $taskState = []): array
    {
        if ($this->failSend) {
            throw new ApiException('send failed', 503);
        }

        $this->started[] = [
            'account_id' => (int)$account['id'],
            'request_id' => (string)$reservation['request_id'],
            'session_id' => (string)$reservation['session_id'],
            'login_method' => (int)($account['login_method'] ?? 1),
            'credential' => $credential,
            'game_password' => $credential,
            'config' => $config,
            'task_state' => $taskState,
        ];
        return $reservation;
    }

    public function reserveValidation(string $validationId, string $requestId, string $sessionId): array
    {
        if (!$this->hasIdleConnection) {
            throw new ApiException('服务器未准备好，请联系管理员', 409);
        }
        return [
            'client_id' => 'client-validation',
            'validation_id' => $validationId,
            'request_id' => $requestId,
            'session_id' => $sessionId,
        ];
    }

    public function sendLoginValidationCommand(array $reservation, int $loginMethod, string $identity, string $credential): array
    {
        if ($this->failSend) {
            throw new ApiException('send failed', 503);
        }
        $this->validations[] = $reservation + [
            'login_method' => $loginMethod,
            'identity' => $identity,
            'credential' => $credential,
        ];
        return $reservation;
    }

    public function restoreValidationConnection(array $reservation): bool
    {
        return true;
    }

    public function discardValidationConnection(array $reservation): void
    {
        $this->discardedValidations[] = $reservation;
    }

    public function releaseReservation(array $reservation): void
    {
        $this->released[] = $reservation;
    }

    public function startAccount(array $account, string $requestId, string $sessionId, string $credential, array $config, array $taskState = []): array
    {
        $reservation = $this->reserveAccount((int)$account['id'], $requestId, $sessionId);
        return $this->sendStartCommand($reservation, $account, $credential, $config, $taskState);
    }

    public function stopAccount(int $accountId, string $requestId): array
    {
        $runtime = [
            'sent' => $this->stopSent,
            'client_id' => 'client-1',
            'request_id' => $requestId,
        ];
        $this->stopped[] = ['account_id' => $accountId, 'request_id' => $requestId];
        return $runtime;
    }

    public function accountConnection(int $accountId): ?array
    {
        foreach ($this->connections as $connection) {
            if ((int)($connection['account_id'] ?? 0) === $accountId) {
                return $connection;
            }
        }
        return null;
    }

    public function stopConnection(string $clientId, string $requestId): array
    {
        $connection = $this->connections[$clientId] ?? null;
        $runtime = [
            'sent' => $connection !== null && !$this->failStopConnection,
            'client_id' => $clientId,
            'request_id' => $requestId,
            'session_id' => (string)($connection['session_id'] ?? ''),
        ];
        $this->stopped[] = [
            'client_id' => $clientId,
            'account_id' => (int)($connection['account_id'] ?? 0),
            'request_id' => $requestId,
            'session_id' => (string)($connection['session_id'] ?? ''),
        ];
        return $runtime;
    }
}
