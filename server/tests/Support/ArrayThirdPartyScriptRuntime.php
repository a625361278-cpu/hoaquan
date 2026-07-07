<?php

namespace tests\Support;

use app\exception\ApiException;
use app\service\ThirdPartyScriptRuntimeInterface;

class ArrayThirdPartyScriptRuntime implements ThirdPartyScriptRuntimeInterface
{
    public array $started = [];
    public array $stopped = [];

    public function __construct(public bool $hasIdleConnection = true)
    {
    }

    public function reserveAccount(int $accountId, string $requestId, string $sessionId): array
    {
        if (!$this->hasIdleConnection) {
            throw new ApiException('脚本未就绪，请联系管理员', 409);
        }

        return [
            'client_id' => 'client-1',
            'request_id' => $requestId,
            'session_id' => $sessionId,
        ];
    }

    public function sendStartCommand(array $reservation, array $account, string $gamePassword, array $config): array
    {
        $this->started[] = [
            'account_id' => (int)$account['id'],
            'request_id' => (string)$reservation['request_id'],
            'session_id' => (string)$reservation['session_id'],
            'game_password' => $gamePassword,
            'config' => $config,
        ];
        return $reservation;
    }

    public function releaseReservation(array $reservation): void
    {
    }

    public function startAccount(array $account, string $requestId, string $sessionId, string $gamePassword, array $config): array
    {
        $reservation = $this->reserveAccount((int)$account['id'], $requestId, $sessionId);
        return $this->sendStartCommand($reservation, $account, $gamePassword, $config);
    }

    public function stopAccount(int $accountId, string $requestId): array
    {
        $runtime = [
            'sent' => true,
            'client_id' => 'client-1',
            'request_id' => $requestId,
        ];
        $this->stopped[] = ['account_id' => $accountId, 'request_id' => $requestId];
        return $runtime;
    }
}
