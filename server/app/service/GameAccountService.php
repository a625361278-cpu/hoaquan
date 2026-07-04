<?php

namespace app\service;

use app\exception\ApiException;
use app\repository\GameAccountRepositoryInterface;
use app\support\ApiResponse;
use app\support\I18n;

class GameAccountService
{
    public const LOCAL_PREVIEW_STATUS = 'local_preview';
    public const LOCAL_UNSYNCED_STATUS = 'local_unsynced';
    public const STARTING_STATUS = 'starting';
    public const RUNNING_STATUS = 'running';
    public const STOPPED_STATUS = 'stopped';
    public const ERROR_STATUS = 'error';
    public const PREVIEW_CHANNEL = 'official_app';

    private array $thirdPartyConfig;
    private string $locale;
    private ThirdPartyCommandQueueInterface $commandQueue;

    public function __construct(
        private GameAccountRepositoryInterface $accounts,
        array|string $thirdPartyConfigOrLocale = [],
        string $locale = I18n::DEFAULT_LOCALE,
        ?ThirdPartyCommandQueueInterface $commandQueue = null
    )
    {
        if (is_string($thirdPartyConfigOrLocale)) {
            $locale = $thirdPartyConfigOrLocale;
            $thirdPartyConfigOrLocale = [];
        }

        $this->thirdPartyConfig = array_merge([
            'enabled' => false,
            'base_url' => '',
            'ws_url' => '',
            'ws_urls' => [],
            'ws_connection_capacity' => 10,
            'sign_secret' => '',
        ], $thirdPartyConfigOrLocale);
        $this->locale = I18n::normalizeLocale($locale);
        $this->commandQueue = $commandQueue ?? new RedisThirdPartyCommandQueue();
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

        $encryptedPassword = $this->cipher()->encrypt($gamePassword);
        $serverId = trim((string)($payload['server_id'] ?? ''));
        $serverName = trim((string)($payload['server_name'] ?? ''));
        $account = $this->accounts->createLocalPreview($userId, [
            'channel_code' => trim((string)($payload['channel_code'] ?? '')) ?: self::PREVIEW_CHANNEL,
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

    public function start(int $userId, int $accountId): array
    {
        $account = $this->requireAccount($userId, $accountId);
        $this->assertThirdPartyWebSocketReady();
        $this->cipher()->decrypt((string)($account['game_password_cipher'] ?? ''));
        $logSessionId = bin2hex(random_bytes(12));

        $updated = $this->accounts->updateRuntimeState($userId, $accountId, [
            'status' => self::STARTING_STATUS,
            'sync_status' => self::LOCAL_UNSYNCED_STATUS,
            'log_session_id' => $logSessionId,
        ]);
        $command = $this->commandQueue->enqueueStart($accountId);

        return ApiResponse::success([
            'account' => $this->publicAccount($updated),
            'command' => $command,
        ], I18n::t('api.game.start_queued', [], $this->locale));
    }

    public function stop(int $userId, int $accountId): array
    {
        $account = $this->requireAccount($userId, $accountId);
        $command = $this->commandQueue->enqueueStop($accountId);

        $updated = $this->accounts->updateRuntimeState($userId, $accountId, [
            'status' => self::STOPPED_STATUS,
            'sync_status' => self::LOCAL_UNSYNCED_STATUS,
            'log_session_id' => '',
        ]);
        $this->accounts->clearLogLines($accountId);

        return ApiResponse::success([
            'account' => $this->publicAccount($updated),
            'command' => $command,
        ], I18n::t('api.game.stopped', [], $this->locale));
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
        $this->requireAccount($userId, $accountId);
        $this->accounts->deleteForUser($userId, $accountId);
        return ApiResponse::success([], I18n::t('api.game.deleted', [], $this->locale));
    }

    public function addQuota(int $userId, int $accountId): array
    {
        $this->requireAccount($userId, $accountId);
        throw new ApiException(I18n::t('api.game.quota_unconfigured', [], $this->locale), 503);
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
            'expire_time' => $account['expire_time'] ?? null,
            'resources' => $this->zeroResources(),
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

    private function assertThirdPartyWebSocketReady(): void
    {
        if (empty($this->thirdPartyConfig['enabled'])) {
            throw new ApiException(I18n::t('api.third_party.disabled', [], $this->locale), 409);
        }

        if (($this->thirdPartyConfig['transport'] ?? ThirdPartyGateway::TRANSPORT_WEBSOCKET) !== ThirdPartyGateway::TRANSPORT_WEBSOCKET) {
            throw new ApiException(I18n::t('api.third_party.websocket_required', [], $this->locale), 503);
        }

        if ($this->configuredWsUrls() === []) {
            throw new ApiException(I18n::t('api.third_party.websocket_unconfigured', [], $this->locale), 503);
        }
    }

    private function configuredWsUrls(): array
    {
        $urls = $this->thirdPartyConfig['ws_urls'] ?? [];
        if (!is_array($urls)) {
            $urls = [];
        }
        $urls = array_values(array_filter(array_map(
            static fn ($url): string => trim((string)$url),
            $urls
        ), static fn (string $url): bool => $url !== ''));

        if ($urls !== []) {
            return $urls;
        }

        $legacyUrl = trim((string)($this->thirdPartyConfig['ws_url'] ?? ''));
        return $legacyUrl === '' ? [] : [$legacyUrl];
    }

    private function cipher(): CredentialCipher
    {
        return new CredentialCipher((string)($this->thirdPartyConfig['credential_key'] ?? ''), $this->locale);
    }

    private function zeroResources(): array
    {
        return [
            'level' => 0,
            'water' => 0,
            'diamond' => 0,
            'gold' => 0,
            'speedCard' => 0,
            'hireBook' => 0,
            'pearl' => 0,
            'floralCoin' => 0,
            'meowCoin' => 0,
            'raceCoin' => '0/0',
            'flowerFinish' => 0,
            'satinFinish' => 0,
            'decorateFinish' => 0,
            'customerFinish' => 0,
        ];
    }

    private function decodeConfig(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $config = json_decode($json, true);
        return is_array($config) ? $config : [];
    }
}
