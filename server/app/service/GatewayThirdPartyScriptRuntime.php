<?php

namespace app\service;

use app\exception\ApiException;
use app\support\I18n;
use GatewayWorker\Lib\Gateway;
use support\Log;
use Throwable;

class GatewayThirdPartyScriptRuntime implements ThirdPartyScriptRuntimeInterface
{
    public function __construct(
        private ?ThirdPartyScriptConnectionStoreInterface $connections = null,
        private string $locale = I18n::DEFAULT_LOCALE,
        private string $registerAddress = '',
        private mixed $sender = null,
        private mixed $sessionUpdater = null,
        private mixed $closer = null
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
        $this->closer ??= static function (string $clientId): void {
            Gateway::closeClient($clientId);
        };
    }

    public function startAccount(array $account, string $requestId, string $sessionId, string $credential, array $config, array $taskState = []): array
    {
        $accountId = (int)($account['id'] ?? 0);
        if ($accountId <= 0) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        $reservation = $this->reserveAccount($accountId, $requestId, $sessionId);
        return $this->sendStartCommand($reservation, $account, $credential, $config, $taskState);
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

    public function reserveValidation(string $validationId, string $requestId, string $sessionId): array
    {
        $connection = $this->connections->allocateIdleForValidation($validationId, $sessionId, $requestId);
        if (!$connection) {
            throw new ApiException(I18n::t('api.third_party.script_not_ready', [], $this->locale), 409);
        }

        return [
            'client_id' => (string)$connection['client_id'],
            'validation_id' => $validationId,
            'request_id' => $requestId,
            'session_id' => $sessionId,
        ];
    }

    public function sendLoginValidationCommand(array $reservation, int $loginMethod, string $identity, string $credential): array
    {
        if (!GameAccountLoginMethod::isSupported($loginMethod)) {
            throw new ApiException(I18n::t('api.game.login_method_invalid', [], $this->locale), 422);
        }
        $clientId = (string)($reservation['client_id'] ?? '');
        if ($clientId === '') {
            throw new ApiException(I18n::t('api.third_party.script_send_failed', ['error' => 'client missing'], $this->locale), 503);
        }

        $payload = [
            'type' => 'login',
            'request_id' => (string)($reservation['request_id'] ?? ''),
            'session_id' => (string)($reservation['session_id'] ?? ''),
            'login_method' => $loginMethod,
        ];
        if ($loginMethod === GameAccountLoginMethod::ACCOUNT_PASSWORD) {
            $payload['game_username'] = $identity;
            $payload['game_password'] = $credential;
        } else {
            $payload['game_uid'] = $identity;
            $payload['token'] = $credential;
        }

        try {
            ($this->sessionUpdater)($clientId, [
                'state' => 'validating',
                'validation_id' => (string)($reservation['validation_id'] ?? ''),
                'session_id' => (string)($reservation['session_id'] ?? ''),
                'request_id' => (string)($reservation['request_id'] ?? ''),
            ]);
            $encodedPayload = $this->encode($payload);
            $sent = ($this->sender)($clientId, $encodedPayload);
        } catch (Throwable $e) {
            $this->connections->releaseClient($clientId);
            throw new ApiException(I18n::t('api.third_party.script_send_failed', ['error' => $e->getMessage()], $this->locale), 503);
        }
        if (!$sent) {
            $this->connections->releaseClient($clientId);
            throw new ApiException(I18n::t('api.third_party.script_send_failed', ['error' => 'client offline'], $this->locale), 503);
        }

        Log::info('Third-party login validation command sent', [
            'client_id' => $clientId,
            'validation_id' => (string)($reservation['validation_id'] ?? ''),
            'request_id' => (string)($reservation['request_id'] ?? ''),
            'session_id' => (string)($reservation['session_id'] ?? ''),
            'login_method' => $loginMethod,
            'payload_bytes' => strlen($encodedPayload),
        ]);
        return $reservation;
    }

    public function restoreValidationConnection(array $reservation): bool
    {
        $clientId = (string)($reservation['client_id'] ?? '');
        if ($clientId === '') {
            return false;
        }
        $state = $this->connections->restoreValidationToIdle(
            $clientId,
            (string)($reservation['validation_id'] ?? ''),
            (string)($reservation['session_id'] ?? ''),
            (string)($reservation['request_id'] ?? '')
        );
        if (!$state) {
            return false;
        }
        ($this->sessionUpdater)($clientId, [
            'authenticated' => true,
            'state' => 'idle',
            'locale' => $this->locale,
        ]);
        return true;
    }

    public function discardValidationConnection(array $reservation): void
    {
        $clientId = (string)($reservation['client_id'] ?? '');
        if ($clientId === '') {
            return;
        }
        $this->connections->releaseClient($clientId);
        ($this->closer)($clientId);
    }

    public function sendStartCommand(array $reservation, array $account, string $credential, array $config, array $taskState = []): array
    {
        $accountId = (int)($account['id'] ?? 0);
        if ($accountId <= 0) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        $clientId = (string)($reservation['client_id'] ?? '');
        if ($clientId === '') {
            throw new ApiException(I18n::t('api.third_party.script_send_failed', ['error' => 'client missing'], $this->locale), 503);
        }

        $loginMethod = (int)($account['login_method'] ?? GameAccountLoginMethod::ACCOUNT_PASSWORD);
        $payload = [
            'type' => 'start',
            'request_id' => (string)($reservation['request_id'] ?? ''),
            'session_id' => (string)($reservation['session_id'] ?? ''),
            'login_method' => $loginMethod,
            'config' => $config,
            'task_state' => $this->normalizeTaskState($taskState),
        ];
        if ($loginMethod === GameAccountLoginMethod::ACCOUNT_PASSWORD) {
            $payload['game_username'] = (string)($account['game_username'] ?? '');
            $payload['game_password'] = $credential;
        } elseif (GameAccountLoginMethod::isSocial($loginMethod)) {
            $payload['game_uid'] = (string)($account['game_uid'] ?? '');
            $payload['token'] = $credential;
        } else {
            throw new ApiException(I18n::t('api.game.login_method_invalid', [], $this->locale), 422);
        }

        try {
            ($this->sessionUpdater)($clientId, [
                'state' => 'bound',
                'account_id' => $accountId,
                'session_id' => (string)($reservation['session_id'] ?? ''),
                'request_id' => (string)($reservation['request_id'] ?? ''),
            ]);
            $encodedPayload = $this->encode($payload);
            $sent = ($this->sender)($clientId, $encodedPayload);
        } catch (Throwable $e) {
            $this->connections->releaseClient($clientId);
            Log::warning('Third-party start command failed', [
                'client_id' => $clientId,
                'account_id' => $accountId,
                'request_id' => (string)($reservation['request_id'] ?? ''),
                'reason' => $e->getMessage(),
            ]);
            throw new ApiException(I18n::t('api.third_party.script_send_failed', ['error' => $e->getMessage()], $this->locale), 503);
        }

        if (!$sent) {
            $this->connections->releaseClient($clientId);
            Log::warning('Third-party start command failed', [
                'client_id' => $clientId,
                'account_id' => $accountId,
                'request_id' => (string)($reservation['request_id'] ?? ''),
                'reason' => 'client offline',
            ]);
            throw new ApiException(I18n::t('api.third_party.script_send_failed', ['error' => 'client offline'], $this->locale), 503);
        }

        Log::info('Third-party start command sent', [
            'client_id' => $clientId,
            'account_id' => $accountId,
            'request_id' => (string)($reservation['request_id'] ?? ''),
            'session_id' => (string)($reservation['session_id'] ?? ''),
            'payload_bytes' => strlen($encodedPayload),
        ]);

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

    private function normalizeTaskState(array $taskState): array
    {
        return [
            'exists' => (bool)($taskState['exists'] ?? false),
            'state' => $taskState['state'] ?? new \stdClass(),
            'saved_at' => $taskState['saved_at'] ?? null,
        ];
    }
}
