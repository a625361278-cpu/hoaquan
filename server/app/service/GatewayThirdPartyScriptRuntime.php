<?php

namespace app\service;

use app\exception\ApiException;
use app\support\I18n;
use GatewayWorker\Lib\Gateway;
use Throwable;

class GatewayThirdPartyScriptRuntime implements ThirdPartyScriptRuntimeInterface
{
    public function __construct(
        private ?ThirdPartyScriptConnectionStoreInterface $connections = null,
        private string $locale = I18n::DEFAULT_LOCALE,
        private string $registerAddress = '',
        private mixed $sender = null,
        private mixed $sessionUpdater = null
    )
    {
        $this->connections ??= new RedisThirdPartyScriptConnectionStore();
        $this->locale = I18n::normalizeLocale($this->locale);
        $this->registerAddress = $this->registerAddress !== ''
            ? $this->registerAddress
            : (string)app_env('GATEWAY_REGISTER_ADDRESS', '127.0.0.1:1238');
        Gateway::$registerAddress = $this->registerAddress;
        $this->sender ??= static fn (string $clientId, string $payload): bool => (bool)Gateway::sendToClient($clientId, $payload);
        $this->sessionUpdater ??= static function (string $clientId, array $session): void {
            Gateway::updateSession($clientId, $session);
        };
    }

    public function startAccount(array $account, string $requestId, string $sessionId, string $gamePassword, array $config): array
    {
        $accountId = (int)($account['id'] ?? 0);
        if ($accountId <= 0) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        $reservation = $this->reserveAccount($accountId, $requestId, $sessionId);
        return $this->sendStartCommand($reservation, $account, $gamePassword, $config);
    }

    public function reserveAccount(int $accountId, string $requestId, string $sessionId): array
    {
        $connection = $this->connections->allocateIdle($accountId, $sessionId, $requestId);
        if (!$connection) {
            throw new ApiException(I18n::t('api.third_party.script_not_ready', [], $this->locale), 409);
        }

        return [
            'client_id' => (string)$connection['client_id'],
            'request_id' => $requestId,
            'session_id' => $sessionId,
        ];
    }

    public function sendStartCommand(array $reservation, array $account, string $gamePassword, array $config): array
    {
        $accountId = (int)($account['id'] ?? 0);
        if ($accountId <= 0) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        $clientId = (string)($reservation['client_id'] ?? '');
        if ($clientId === '') {
            throw new ApiException(I18n::t('api.third_party.script_send_failed', ['error' => 'client missing'], $this->locale), 503);
        }

        $payload = [
            'type' => 'start',
            'request_id' => (string)($reservation['request_id'] ?? ''),
            'session_id' => (string)($reservation['session_id'] ?? ''),
            'game_username' => (string)($account['game_username'] ?? ''),
            'game_password' => $gamePassword,
            'config' => $config,
        ];

        try {
            ($this->sessionUpdater)($clientId, [
                'state' => 'bound',
                'account_id' => $accountId,
                'session_id' => (string)($reservation['session_id'] ?? ''),
                'request_id' => (string)($reservation['request_id'] ?? ''),
            ]);
            $sent = ($this->sender)($clientId, $this->encode($payload));
        } catch (Throwable $e) {
            $this->connections->releaseClient($clientId);
            throw new ApiException(I18n::t('api.third_party.script_send_failed', ['error' => $e->getMessage()], $this->locale), 503);
        }

        if (!$sent) {
            $this->connections->releaseClient($clientId);
            throw new ApiException(I18n::t('api.third_party.script_send_failed', ['error' => 'client offline'], $this->locale), 503);
        }

        return [
            'client_id' => $clientId,
            'request_id' => (string)($reservation['request_id'] ?? ''),
            'session_id' => (string)($reservation['session_id'] ?? ''),
        ];
    }

    public function releaseReservation(array $reservation): void
    {
        $clientId = (string)($reservation['client_id'] ?? '');
        if ($clientId !== '') {
            $this->connections->releaseClient($clientId);
        }
    }

    public function stopAccount(int $accountId, string $requestId): array
    {
        $connection = $this->connections->markStopping($accountId);
        if (!$connection) {
            return [
                'sent' => false,
                'request_id' => $requestId,
            ];
        }

        $clientId = (string)$connection['client_id'];
        $payload = [
            'type' => 'stop',
            'request_id' => $requestId,
            'session_id' => (string)($connection['session_id'] ?? ''),
        ];

        try {
            ($this->sessionUpdater)($clientId, ['state' => 'stopping']);
            $sent = ($this->sender)($clientId, $this->encode($payload));
        } catch (Throwable) {
            $sent = false;
        }

        return [
            'sent' => (bool)$sent,
            'client_id' => $clientId,
            'request_id' => $requestId,
        ];
    }

    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
