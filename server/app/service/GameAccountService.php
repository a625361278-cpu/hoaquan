<?php

namespace app\service;

use app\exception\ApiException;
use app\repository\GameAccountRepositoryInterface;
use app\support\ApiResponse;
use app\support\I18n;
use support\Log;

class GameAccountService
{
    public const LOCAL_PREVIEW_STATUS = 'local_preview';
    public const LOCAL_UNSYNCED_STATUS = 'local_unsynced';
    public const STARTING_STATUS = 'starting';
    public const RUNNING_STATUS = 'running';
    public const RECONNECTING_STATUS = 'reconnecting';
    public const STOPPING_STATUS = 'stopping';
    public const STOPPED_STATUS = 'stopped';
    public const ERROR_STATUS = 'error';
    public const PREVIEW_CHANNEL = 'official_app';
    private const SUPPORTED_CHANNELS = [self::PREVIEW_CHANNEL];

    private array $thirdPartyConfig;
    private string $locale;
    private ThirdPartyScriptRuntimeInterface $scriptRuntime;
    private GameAccountResourceService $resources;
    private GameAccountQuotaService $quotaService;
    private GameLogSinkInterface $logs;

    public function __construct(
        private GameAccountRepositoryInterface $accounts,
        array|string $thirdPartyConfigOrLocale = [],
        string $locale = I18n::DEFAULT_LOCALE,
        ?ThirdPartyScriptRuntimeInterface $scriptRuntime = null,
        ?GameAccountResourceService $resources = null,
        ?GameAccountQuotaService $quotaService = null,
        ?GameLogSinkInterface $logs = null
    )
    {
        if (is_string($thirdPartyConfigOrLocale)) {
            $locale = $thirdPartyConfigOrLocale;
            $thirdPartyConfigOrLocale = [];
        }

        $this->thirdPartyConfig = array_merge([
            'enabled' => false,
            'base_url' => '',
            'script_token' => '',
            'script_ws_url' => '',
            'sign_secret' => '',
        ], $thirdPartyConfigOrLocale);
        $this->locale = I18n::normalizeLocale($locale);
        $this->scriptRuntime = $scriptRuntime ?? new GatewayThirdPartyScriptRuntime(locale: $this->locale);
        $this->resources = $resources ?? new GameAccountResourceService();
        $this->quotaService = $quotaService ?? new GameAccountQuotaService(locale: $this->locale);
        $this->logs = $logs ?? new GameLogQueue();
    }

    public function listForUser(int $userId): array
    {
        return ApiResponse::success([
            'items' => array_map([$this, 'publicAccount'], $this->accounts->listByUserId($userId)),
            'empty_text' => I18n::t('api.game.account_empty', [], $this->locale),
        ]);
    }

    public function createPlaceholder(): never
    {
        throw new ApiException(I18n::t('api.game.create_unavailable', [], $this->locale), 409);
    }

    public function createFromLogin(int $userId, array $payload): array
    {
        $gameUsername = trim((string)($payload['game_username'] ?? ''));
        $gamePassword = trim((string)($payload['game_password'] ?? ''));
        if ($gameUsername === '' || $gamePassword === '') {
            throw new ApiException(I18n::t('api.game.require_game_credentials', [], $this->locale), 422);
        }

        $channelCode = trim((string)($payload['channel_code'] ?? '')) ?: self::PREVIEW_CHANNEL;
        if (!in_array($channelCode, self::SUPPORTED_CHANNELS, true)) {
            throw new ApiException(I18n::t('api.game.channel_unsupported', [], $this->locale), 422);
        }

        $encryptedPassword = $this->cipher()->encrypt($gamePassword);
        $serverId = trim((string)($payload['server_id'] ?? ''));
        $serverName = trim((string)($payload['server_name'] ?? ''));
        $account = $this->accounts->createLocalPreview($userId, [
            'channel_code' => $channelCode,
            'game_username' => $gameUsername,
            'game_password_cipher' => $encryptedPassword,
            'server_id' => $serverId,
            'server_name' => $serverName,
            'display_name' => $gameUsername,
            'remark' => I18n::t('api.game.local_preview_remark', [], $this->locale),
        ]);

        return ApiResponse::success([
            'account' => $this->publicAccount($account),
            'preview_mode' => empty($this->thirdPartyConfig['enabled']),
        ], I18n::t('api.game.local_preview_created', [], $this->locale));
    }

    public function configForUser(int $userId, int $accountId): array
    {
        $account = $this->accounts->findByUserId($userId, $accountId);
        if (!$account) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        return ApiResponse::success([
            'account' => $this->publicAccount($account),
            'config' => $this->decodeConfig($account['config_json'] ?? '{}'),
            'sync_status' => (string)($account['sync_status'] ?? self::LOCAL_UNSYNCED_STATUS),
        ]);
    }

    public function configForThirdParty(int $accountId): array
    {
        $account = $this->accounts->findById($accountId);
        if (!$account) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        return ApiResponse::success([
            'account' => $this->publicAccount($account),
            'config' => $this->decodeConfig($account['config_json'] ?? '{}'),
            'sync_status' => (string)($account['sync_status'] ?? self::LOCAL_UNSYNCED_STATUS),
            'updated_at' => $account['updated_at'] ?? null,
        ]);
    }

    public function saveConfig(int $userId, int $accountId, array $config): array
    {
        if (!$this->accounts->findByUserId($userId, $accountId)) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        $account = $this->accounts->saveLocalConfig($userId, $accountId, $config, self::LOCAL_UNSYNCED_STATUS);
        return ApiResponse::success([
            'account' => $this->publicAccount($account),
            'config' => $config,
            'sync_status' => self::LOCAL_UNSYNCED_STATUS,
        ], I18n::t('api.game.local_config_saved', [], $this->locale));
    }

    public function importConfig(int $userId, int $accountId, int $sourceAccountId): array
    {
        $this->requireAccount($userId, $accountId);
        if ($sourceAccountId <= 0) {
            throw new ApiException(I18n::t('api.game.import_source_required', [], $this->locale), 422);
        }
        if ($accountId === $sourceAccountId) {
            throw new ApiException(I18n::t('api.game.import_same_account', [], $this->locale), 422);
        }

        $sourceAccount = $this->accounts->findByUserId($userId, $sourceAccountId);
        if (!$sourceAccount) {
            throw new ApiException(I18n::t('api.game.import_source_not_found', [], $this->locale), 404);
        }

        $sourceConfig = $this->decodeSourceConfig((string)($sourceAccount['config_json'] ?? ''));
        if ($sourceConfig === []) {
            throw new ApiException(I18n::t('api.game.import_source_empty', [], $this->locale), 422);
        }

        $account = $this->accounts->saveLocalConfig($userId, $accountId, $sourceConfig, self::LOCAL_UNSYNCED_STATUS);
        return ApiResponse::success([
            'account' => $this->publicAccount($account),
            'config' => $sourceConfig,
            'sync_status' => self::LOCAL_UNSYNCED_STATUS,
        ], I18n::t('api.game.config_imported', [], $this->locale));
    }

    public function start(int $userId, int $accountId): array
    {
        $account = $this->requireAccount($userId, $accountId);
        $this->assertQuotaActive($account);
        $this->assertThirdPartyScriptReady();
        $gamePassword = $this->cipher()->decrypt((string)($account['game_password_cipher'] ?? ''));
        $logSessionId = bin2hex(random_bytes(12));
        $requestId = bin2hex(random_bytes(16));
        $reservation = $this->scriptRuntime->reserveAccount($accountId, $requestId, $logSessionId);

        try {
            $this->resources->clear($accountId);
            $this->accounts->clearNormalLogLines($accountId, null);
            $updated = $this->accounts->updateRuntimeState($userId, $accountId, [
                'status' => self::STARTING_STATUS,
                'sync_status' => self::LOCAL_UNSYNCED_STATUS,
                'log_session_id' => $logSessionId,
                'desired_running' => 1,
                'auto_restart_attempts' => 0,
                'auto_restart_next_at' => null,
                'auto_restart_last_error' => '',
            ]);
        } catch (\Throwable $e) {
            $this->scriptRuntime->releaseReservation($reservation);
            throw $e;
        }

        try {
            $runtime = $this->scriptRuntime->sendStartCommand(
                $reservation,
                $account,
                $gamePassword,
                $this->decodeConfig((string)($account['config_json'] ?? '{}'))
            );
        } catch (\Throwable $e) {
            $this->accounts->updateRuntimeState($userId, $accountId, [
                'status' => (string)($account['status'] ?? self::STOPPED_STATUS),
                'sync_status' => (string)($account['sync_status'] ?? self::LOCAL_UNSYNCED_STATUS),
                'log_session_id' => (string)($account['log_session_id'] ?? ''),
                'desired_running' => (int)($account['desired_running'] ?? 0),
                'auto_restart_attempts' => (int)($account['auto_restart_attempts'] ?? 0),
                'auto_restart_next_at' => $account['auto_restart_next_at'] ?? null,
                'auto_restart_last_error' => (string)($account['auto_restart_last_error'] ?? ''),
            ]);
            throw $e;
        }

        $startTrace = [
            'account_id' => $accountId,
            'client_id' => (string)($runtime['client_id'] ?? ''),
            'request_id' => (string)($runtime['request_id'] ?? ''),
            'session_id' => (string)($runtime['session_id'] ?? ''),
        ];
        Log::info('Third-party start sent', $startTrace);
        $this->logs->enqueueNormal($accountId, ['[INFO] 已下发 start：' . json_encode($startTrace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], $logSessionId);

        return ApiResponse::success([
            'account' => $this->publicAccount($updated),
            'runtime' => $runtime,
        ], I18n::t('api.game.start_queued', [], $this->locale));
    }

    public function stop(int $userId, int $accountId): array
    {
        $account = $this->requireAccount($userId, $accountId);
        $runtime = $this->scriptRuntime->stopAccount($accountId, bin2hex(random_bytes(16)));

        if (!($runtime['sent'] ?? false)) {
            $this->resources->clear($accountId);
            $updated = $this->accounts->updateRuntimeState($userId, $accountId, [
                'status' => self::STOPPED_STATUS,
                'sync_status' => self::LOCAL_UNSYNCED_STATUS,
                'log_session_id' => '',
                'desired_running' => 0,
                'auto_restart_attempts' => 0,
                'auto_restart_next_at' => null,
                'auto_restart_last_error' => '',
            ]);
            $this->accounts->clearNormalLogLines($accountId, null);

            return ApiResponse::success([
                'account' => $this->publicAccount($updated),
                'runtime' => $runtime,
            ], I18n::t('api.game.stopped', [], $this->locale));
        }

        $updated = $this->accounts->updateRuntimeState($userId, $accountId, [
            'status' => self::STOPPING_STATUS,
            'sync_status' => self::LOCAL_UNSYNCED_STATUS,
            'desired_running' => 0,
            'auto_restart_attempts' => 0,
            'auto_restart_next_at' => null,
            'auto_restart_last_error' => '',
        ]);
        $this->resources->clear($accountId);

        return ApiResponse::success([
            'account' => $this->publicAccount($updated),
            'runtime' => $runtime,
        ], I18n::t('api.game.stop_queued', [], $this->locale));
    }

    public function updatePassword(int $userId, int $accountId, string $password): array
    {
        if (trim($password) === '') {
            throw new ApiException(I18n::t('api.game.require_game_credentials', [], $this->locale), 422);
        }

        $this->requireAccount($userId, $accountId);
        $account = $this->accounts->updateCredentials($userId, $accountId, $this->cipher()->encrypt($password));

        return ApiResponse::success([
            'account' => $this->publicAccount($account),
        ], I18n::t('api.game.password_updated', [], $this->locale));
    }

    public function delete(int $userId, int $accountId): array
    {
        $account = $this->requireAccount($userId, $accountId);
        if ($this->isActiveRuntimeStatus((string)($account['status'] ?? ''))) {
            $this->scriptRuntime->stopAccount($accountId, bin2hex(random_bytes(16)));
        }
        $this->resources->clear($accountId);
        $this->accounts->deleteForUser($userId, $accountId);
        return ApiResponse::success([], I18n::t('api.game.deleted', [], $this->locale));
    }

    public function addQuota(int $userId, int $accountId, int $extraPoints = 0): array
    {
        $this->requireAccount($userId, $accountId);
        $result = $this->quotaService->extendAccount($userId, $accountId, $extraPoints);
        return ApiResponse::success([
            'account' => $this->publicAccount($result['account']),
            'balance' => $result['balance'],
            'cost_points' => $result['cost_points'],
            'add_days' => $result['add_days'],
            'expire_time' => $result['expire_time'],
        ], I18n::t('api.game.quota_extended', [], $this->locale));
    }

    private function publicAccount(array $account): array
    {
        return [
            'id' => (int)$account['id'],
            'display_name' => (string)$account['display_name'],
            'game_username' => (string)($account['game_username'] ?? ''),
            'channel_code' => (string)($account['channel_code'] ?? self::PREVIEW_CHANNEL),
            'server_id' => (string)($account['server_id'] ?? ''),
            'server_name' => (string)($account['server_name'] ?? ''),
            'status' => (string)$account['status'],
            'sync_status' => (string)($account['sync_status'] ?? ''),
            'third_party_account_id' => (string)($account['third_party_account_id'] ?? ''),
            'log_session_id' => (string)($account['log_session_id'] ?? ''),
            'desired_running' => (int)($account['desired_running'] ?? 0),
            'auto_restart_attempts' => (int)($account['auto_restart_attempts'] ?? 0),
            'auto_restart_next_at' => $account['auto_restart_next_at'] ?? null,
            'expire_time' => $account['expire_time'] ?? null,
            'has_config' => $this->hasSavedConfig((string)($account['config_json'] ?? '')),
            'resources' => $this->resources->resourcesForAccount((int)$account['id']),
            'remark' => (string)($account['remark'] ?? ''),
            'created_at' => $account['created_at'] ?? null,
        ];
    }

    private function requireAccount(int $userId, int $accountId): array
    {
        $account = $this->accounts->findByUserId($userId, $accountId);
        if (!$account) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }
        return $account;
    }

    private function assertThirdPartyScriptReady(): void
    {
        if (empty($this->thirdPartyConfig['enabled'])) {
            throw new ApiException(I18n::t('api.game.server_disabled', [], $this->locale), 409);
        }

        if (($this->thirdPartyConfig['transport'] ?? ThirdPartyGateway::TRANSPORT_WEBSOCKET) !== ThirdPartyGateway::TRANSPORT_WEBSOCKET) {
            throw new ApiException(I18n::t('api.game.server_config_invalid', [], $this->locale), 503);
        }

        if (trim((string)($this->thirdPartyConfig['script_token'] ?? '')) === '') {
            throw new ApiException(I18n::t('api.game.server_config_invalid', [], $this->locale), 503);
        }
    }

    private function assertQuotaActive(array $account): void
    {
        $expireTime = trim((string)($account['expire_time'] ?? ''));
        if ($expireTime === '') {
            throw new ApiException(I18n::t('api.game.quota_expired', [], $this->locale), 409);
        }

        $expireTimestamp = strtotime($expireTime);
        if ($expireTimestamp === false) {
            throw new \RuntimeException('游戏账号到期时间格式异常：' . $expireTime);
        }
        if ($expireTimestamp <= time()) {
            throw new ApiException(I18n::t('api.game.quota_expired', [], $this->locale), 409);
        }
    }

    private function isActiveRuntimeStatus(string $status): bool
    {
        return in_array($status, [self::STARTING_STATUS, self::RUNNING_STATUS, self::RECONNECTING_STATUS, self::STOPPING_STATUS], true);
    }

    private function cipher(): CredentialCipher
    {
        return new CredentialCipher((string)($this->thirdPartyConfig['credential_key'] ?? ''), $this->locale);
    }

    private function decodeConfig(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $config = json_decode($json, true);
        return is_array($config) ? $config : [];
    }

    private function decodeSourceConfig(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $config = json_decode($json, true);
        if (!is_array($config)) {
            throw new ApiException(I18n::t('api.game.import_source_invalid', [], $this->locale), 422);
        }
        if ($config !== [] && array_is_list($config)) {
            throw new ApiException(I18n::t('api.game.import_source_invalid', [], $this->locale), 422);
        }

        return $config;
    }

    private function hasSavedConfig(string $json): bool
    {
        if (trim($json) === '') {
            return false;
        }

        $config = json_decode($json, true);
        return is_array($config) && $config !== [] && !array_is_list($config);
    }
}
