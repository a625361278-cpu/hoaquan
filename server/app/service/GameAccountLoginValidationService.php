<?php

namespace app\service;

use app\exception\ApiException;
use app\repository\GameAccountRepositoryInterface;
use app\support\ApiResponse;
use app\support\I18n;
use support\Log;
use Throwable;

class GameAccountLoginValidationService
{
    public const TIMEOUT_SECONDS = 20;

    public function __construct(
        private GameAccountRepositoryInterface $accounts,
        private array $thirdPartyConfig,
        private string $locale = I18n::DEFAULT_LOCALE,
        private ?ThirdPartyScriptRuntimeInterface $runtime = null,
        private ?GameAccountLoginValidationStoreInterface $validations = null
    ) {
        $this->locale = I18n::normalizeLocale($this->locale);
        $this->runtime ??= new GatewayThirdPartyScriptRuntime(locale: $this->locale);
        $this->validations ??= new RedisGameAccountLoginValidationStore();
    }

    public function begin(int $userId, array $payload): array
    {
        $prepared = $this->prepareCredentials($payload);
        $this->assertThirdPartyReady();
        $maxAccounts = (int)($this->thirdPartyConfig['max_accounts_per_user'] ?? SystemSettingService::DEFAULT_GAME_ACCOUNT_MAX_COUNT);
        if (count($this->accounts->listByUserId($userId)) >= $maxAccounts) {
            throw new ApiException(I18n::t('api.game.account_limit_reached', ['limit' => $maxAccounts], $this->locale), 409);
        }

        $now = time();
        $validationId = bin2hex(random_bytes(16));
        $requestId = bin2hex(random_bytes(16));
        $sessionId = bin2hex(random_bytes(12));
        $fingerprint = hash_hmac('sha256', json_encode([
            $prepared['channel_code'],
            $prepared['login_method'],
            $prepared['identity'],
            $prepared['credential'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->credentialKey());
        $job = [
            'validation_id' => $validationId,
            'user_id' => $userId,
            'status' => 'reserving',
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'client_id' => '',
            'fingerprint' => $fingerprint,
            'channel_code' => $prepared['channel_code'],
            'login_method' => $prepared['login_method'],
            'identity' => $prepared['identity'],
            'credential_cipher' => $this->cipher()->encrypt($prepared['credential']),
            'locale' => $this->locale,
            'message' => '',
            'account_id' => 0,
            'server_name' => '',
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now + self::TIMEOUT_SECONDS,
        ];

        $begin = $this->validations->begin($job);
        if ($begin['kind'] === 'existing') {
            if (($begin['job']['status'] ?? '') === 'success'
                && !$this->accounts->findByUserId($userId, (int)($begin['job']['account_id'] ?? 0))) {
                $this->validations->forget((string)$begin['job']['validation_id']);
                return $this->begin($userId, $payload);
            }
            return ApiResponse::success($this->responseData($begin['job']));
        }
        if ($begin['kind'] === 'conflict') {
            throw new ApiException(I18n::t('api.game.login_validation_conflict', [], $this->locale), 409);
        }

        $reservation = null;
        try {
            $reservation = $this->runtime->reserveValidation($validationId, $requestId, $sessionId);
            $this->validations->activate($validationId, (string)$reservation['client_id']);
            $this->runtime->sendLoginValidationCommand(
                $reservation,
                $prepared['login_method'],
                $prepared['identity'],
                $prepared['credential']
            );
        } catch (Throwable $e) {
            $this->validations->abortStart($validationId);
            if (is_array($reservation)) {
                $this->runtime->discardValidationConnection($reservation);
            }
            throw $e;
        }

        return ApiResponse::success($this->responseData($this->validations->getForUser($userId, $validationId) ?? $job));
    }

    public function status(int $userId, string $validationId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $validationId)) {
            throw new ApiException(I18n::t('api.game.login_validation_not_found', [], $this->locale), 404);
        }
        $job = $this->validations->getForUser($userId, $validationId);
        if (!$job) {
            throw new ApiException(I18n::t('api.game.login_validation_not_found', [], $this->locale), 404);
        }
        return ApiResponse::success($this->responseData($job));
    }

    public function completeFromThirdParty(string $clientId, array $payload, array $connectionState): bool
    {
        $validationId = (string)($connectionState['validation_id'] ?? '');
        $requestId = (string)($payload['request_id'] ?? '');
        $sessionId = (string)($payload['session_id'] ?? '');
        $job = $this->validations->claimResponse($validationId, $requestId, $sessionId);
        if (!$job) {
            $this->validations->failPending($validationId, I18n::t('api.game.login_validation_context_invalid', [], $this->locale));
            return false;
        }

        $reservation = [
            'client_id' => $clientId,
            'validation_id' => $validationId,
            'request_id' => (string)$job['request_id'],
            'session_id' => (string)$job['session_id'],
        ];
        $code = (int)$payload['code'];
        $message = (string)$payload['msg'];
        try {
            if ($code === 0) {
                $this->validations->complete($validationId, 'rejected', $message);
            } else {
                $serverName = trim((string)$payload['server_name']);
                $account = $this->createValidatedAccount($job, $serverName);
                if ($account === null) {
                    $this->validations->complete(
                        $validationId,
                        'error',
                        I18n::t('api.game.account_limit_reached', [
                            'limit' => (int)$this->thirdPartyConfig['max_accounts_per_user'],
                        ], (string)$job['locale'])
                    );
                } else {
                    $this->validations->complete($validationId, 'success', $message, (int)$account['id'], $serverName);
                }
            }
        } catch (Throwable $e) {
            Log::error('Game account login validation account creation failed', [
                'validation_id' => $validationId,
                'user_id' => (int)$job['user_id'],
                'login_method' => (int)$job['login_method'],
                'error' => $e->getMessage(),
            ]);
            $this->validations->complete($validationId, 'error', I18n::t('api.game.login_validation_create_failed', [], (string)$job['locale']));
        }

        if (!$this->runtime->restoreValidationConnection($reservation)) {
            Log::warning('Login validation connection could not return to idle', [
                'client_id' => $clientId,
                'validation_id' => $validationId,
                'request_id' => (string)$job['request_id'],
                'session_id' => (string)$job['session_id'],
            ]);
        }
        return true;
    }

    public function failProtocol(string $validationId, string $message): void
    {
        $this->validations->failPending($validationId, $message);
    }

    private function createValidatedAccount(array $job, string $serverName): ?array
    {
        $loginMethod = (int)$job['login_method'];
        $identity = (string)$job['identity'];
        return $this->accounts->createLocalPreviewWithinLimit((int)$job['user_id'], [
            'channel_code' => (string)$job['channel_code'],
            'login_method' => $loginMethod,
            'game_username' => $loginMethod === GameAccountLoginMethod::ACCOUNT_PASSWORD ? $identity : '',
            'game_uid' => GameAccountLoginMethod::isSocial($loginMethod) ? $identity : '',
            'game_password_cipher' => $loginMethod === GameAccountLoginMethod::ACCOUNT_PASSWORD ? (string)$job['credential_cipher'] : null,
            'game_token_cipher' => GameAccountLoginMethod::isSocial($loginMethod) ? (string)$job['credential_cipher'] : null,
            'server_id' => '',
            'server_name' => $serverName,
            'display_name' => $identity,
            'remark' => I18n::t('api.game.login_validated_remark', [], (string)$job['locale']),
        ], (int)$this->thirdPartyConfig['max_accounts_per_user']);
    }

    private function prepareCredentials(array $payload): array
    {
        $raw = $payload['login_method'] ?? GameAccountLoginMethod::ACCOUNT_PASSWORD;
        if (!is_int($raw) && !(is_string($raw) && preg_match('/^\d+$/', $raw))) {
            throw new ApiException(I18n::t('api.game.login_method_invalid', [], $this->locale), 422);
        }
        $method = (int)$raw;
        if (!GameAccountLoginMethod::isSupported($method)) {
            throw new ApiException(I18n::t('api.game.login_method_invalid', [], $this->locale), 422);
        }
        if (($method === GameAccountLoginMethod::FACEBOOK && empty($this->thirdPartyConfig['facebook_login_enabled']))
            || ($method === GameAccountLoginMethod::GOOGLE && empty($this->thirdPartyConfig['google_login_enabled']))) {
            throw new ApiException(I18n::t('api.game.login_method_disabled', [], $this->locale), 422);
        }

        if ($method === GameAccountLoginMethod::ACCOUNT_PASSWORD) {
            $identity = trim((string)($payload['game_username'] ?? ''));
            $credential = trim((string)($payload['game_password'] ?? ''));
            if ($identity === '' || $credential === '') {
                throw new ApiException(I18n::t('api.game.require_game_credentials', [], $this->locale), 422);
            }
        } else {
            $identity = trim((string)($payload['game_uid'] ?? ''));
            $credential = trim((string)($payload['token'] ?? ''));
            if ($identity === '' || mb_strlen($identity) > 128 || preg_match('/[\x00-\x1F\x7F]/u', $identity) || $credential === '') {
                throw new ApiException(I18n::t('api.game.require_social_credentials', [], $this->locale), 422);
            }
        }
        $channel = trim((string)($payload['channel_code'] ?? '')) ?: GameAccountService::PREVIEW_CHANNEL;
        if ($channel !== GameAccountService::PREVIEW_CHANNEL) {
            throw new ApiException(I18n::t('api.game.channel_unsupported', [], $this->locale), 422);
        }
        return ['login_method' => $method, 'identity' => $identity, 'credential' => $credential, 'channel_code' => $channel];
    }

    private function responseData(array $job): array
    {
        $status = in_array((string)($job['status'] ?? ''), ['reserving', 'processing'], true)
            ? 'verifying'
            : (string)$job['status'];
        $result = [
            'validation_id' => (string)$job['validation_id'],
            'request_id' => (string)$job['request_id'],
            'session_id' => (string)$job['session_id'],
            'status' => $status,
            'expires_in' => max(0, (int)$job['expires_at'] - time()),
        ];
        if (in_array($status, ['success', 'rejected', 'timeout', 'error'], true)) {
            $result['message'] = (string)($job['message'] ?? '');
        }
        if ($status === 'success') {
            $account = $this->accounts->findByUserId((int)$job['user_id'], (int)$job['account_id']);
            if (!$account) {
                throw new \RuntimeException('登录验证成功但已创建账号无法读取');
            }
            $result['account'] = $this->createdAccountData($account);
        }
        return $result;
    }

    private function createdAccountData(array $account): array
    {
        return [
            'id' => (int)$account['id'],
            'display_name' => (string)$account['display_name'],
            'game_username' => (string)($account['game_username'] ?? ''),
            'game_uid' => (string)($account['game_uid'] ?? ''),
            'channel_code' => (string)($account['channel_code'] ?? GameAccountService::PREVIEW_CHANNEL),
            'login_method' => (int)($account['login_method'] ?? GameAccountLoginMethod::ACCOUNT_PASSWORD),
            'server_id' => '',
            'server_name' => (string)($account['server_name'] ?? ''),
            'status' => (string)$account['status'],
            'sync_status' => (string)($account['sync_status'] ?? ''),
            'third_party_account_id' => '',
            'log_session_id' => '',
            'desired_running' => 0,
            'auto_restart_attempts' => 0,
            'auto_restart_next_at' => null,
            'expire_time' => null,
            'has_config' => false,
            'resources' => [],
            'remark' => (string)($account['remark'] ?? ''),
            'created_at' => $account['created_at'] ?? null,
        ];
    }

    private function assertThirdPartyReady(): void
    {
        if (empty($this->thirdPartyConfig['enabled'])) {
            throw new ApiException(I18n::t('api.game.server_disabled', [], $this->locale), 409);
        }
        if (($this->thirdPartyConfig['transport'] ?? ThirdPartyGateway::TRANSPORT_WEBSOCKET) !== ThirdPartyGateway::TRANSPORT_WEBSOCKET
            || trim((string)($this->thirdPartyConfig['script_token'] ?? '')) === '') {
            throw new ApiException(I18n::t('api.game.server_config_invalid', [], $this->locale), 503);
        }
        $this->credentialKey();
    }

    private function credentialKey(): string
    {
        $key = trim((string)($this->thirdPartyConfig['credential_key'] ?? ''));
        if ($key === '') {
            throw new ApiException(I18n::t('api.game.password_key_missing', [], $this->locale), 503);
        }
        return $key;
    }

    private function cipher(): CredentialCipher
    {
        return new CredentialCipher($this->credentialKey(), $this->locale);
    }
}
