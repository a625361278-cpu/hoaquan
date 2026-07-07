<?php

namespace plugin\webman\gateway;

use app\repository\DbGameAccountRepository;
use app\repository\DbUserRepository;
use app\service\GameAccountService;
use app\service\GameLogQueue;
use app\service\ProfileService;
use app\service\RedisThirdPartyScriptConnectionStore;
use app\service\SystemSettingService;
use app\support\I18n;
use GatewayWorker\Lib\Gateway;
use support\Log;
use Throwable;

class Events
{
    private const MAX_MESSAGE_BYTES = 8192;

    public static function onWorkerStart($worker): void
    {
        Gateway::$registerAddress = '127.0.0.1:1236';
    }

    public static function onWebSocketConnect($clientId, $data): void
    {
        try {
            $query = self::queryFromHandshake((string)$data);
            $locale = I18n::normalizeLocale((string)($query['locale'] ?? I18n::DEFAULT_LOCALE));
            $settings = new SystemSettingService();
            $config = $settings->thirdPartyConfig();
            if (empty($config['enabled'])) {
                self::closeWithError($clientId, I18n::t('api.third_party.disabled', [], $locale));
                return;
            }

            $expectedToken = (string)($config['script_token'] ?? '');
            $actualToken = trim((string)($query['token'] ?? ''));
            if ($expectedToken === '' || !hash_equals($expectedToken, $actualToken)) {
                self::closeWithError($clientId, I18n::t('api.third_party.script_token_invalid', [], $locale));
                return;
            }

            $metadata = [
                'remote_ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                'script_version' => (string)($query['version'] ?? ''),
            ];
            $state = self::store()->registerIdle($clientId, $metadata);
            Gateway::setSession($clientId, [
                'authenticated' => true,
                'state' => 'idle',
                'locale' => $locale,
            ]);
            Gateway::sendToClient($clientId, self::json([
                'type' => 'ready',
                'client_id' => $clientId,
                'state' => $state['state'],
                'server_time' => time(),
            ]));
        } catch (Throwable $e) {
            Log::error('Third-party script websocket auth failed', ['client_id' => $clientId, 'error' => $e->getMessage()]);
            self::closeWithError($clientId, $e->getMessage());
        }
    }

    public static function onMessage($clientId, $message): void
    {
        try {
            if (strlen((string)$message) > self::MAX_MESSAGE_BYTES) {
                self::closeWithError($clientId, 'message too large');
                return;
            }

            $payload = json_decode((string)$message, true);
            if (!is_array($payload)) {
                self::closeWithError($clientId, 'invalid json');
                return;
            }

            $state = self::store()->heartbeat($clientId, [
                'script_version' => $payload['script_version'] ?? null,
            ]);
            if (!$state) {
                self::closeWithError($clientId, 'connection state missing');
                return;
            }

            $type = (string)($payload['type'] ?? 'log');
            if (in_array($type, ['ping', 'pong', 'heartbeat'], true)) {
                Gateway::sendToClient($clientId, self::json(['type' => 'pong', 'server_time' => time()]));
                return;
            }

            $accountId = (int)($state['account_id'] ?? 0);
            if ($accountId <= 0 || !in_array((string)($state['state'] ?? ''), ['bound', 'stopping'], true)) {
                self::closeWithError($clientId, 'connection is not bound to a game account');
                return;
            }
            $sessionId = (string)($state['session_id'] ?? '');

            match ($type) {
                'started' => self::markStarted($accountId, $payload, $sessionId),
                'log' => self::appendLogPayload($accountId, $payload, $sessionId),
                'event' => self::appendEventPayload($accountId, $payload),
                'status' => self::appendStatusPayload($accountId, $payload, $sessionId),
                'error' => self::markError($clientId, $accountId, (string)($payload['message'] ?? '第三方脚本返回异常'), $sessionId),
                'stopped' => self::markStopped($clientId, $accountId),
                default => self::appendLogLines($accountId, [self::json($payload)], $sessionId),
            };
        } catch (Throwable $e) {
            Log::error('Third-party script websocket message failed', ['client_id' => $clientId, 'error' => $e->getMessage()]);
            self::closeWithError($clientId, $e->getMessage());
        }
    }

    public static function onClose($clientId): void
    {
        $state = self::store()->releaseClient($clientId);
        if (!$state || (int)($state['account_id'] ?? 0) <= 0) {
            return;
        }

        $accountId = (int)$state['account_id'];
        if (($state['state'] ?? '') === 'stopping') {
            self::markStoppedLocally($accountId);
            return;
        }

        $account = self::accounts()->findById($accountId);
        if (!$account || !in_array((string)($account['status'] ?? ''), [GameAccountService::STARTING_STATUS, GameAccountService::RUNNING_STATUS], true)) {
            return;
        }

        self::accounts()->updateRuntimeState((int)$account['user_id'], $accountId, [
            'status' => GameAccountService::ERROR_STATUS,
            'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
        ]);
        self::appendLogLines($accountId, ['[ERROR] 第三方脚本连接断开'], (string)($state['session_id'] ?? ''));
    }

    private static function markStarted(int $accountId, array $payload, string $sessionId): void
    {
        $account = self::accounts()->findById($accountId);
        if (!$account) {
            return;
        }

        $updated = self::accounts()->updateRuntimeState((int)$account['user_id'], $accountId, [
            'status' => GameAccountService::RUNNING_STATUS,
            'sync_status' => 'synced',
            'display_name' => (string)($payload['display_name'] ?? ($account['display_name'] ?? '')),
            'third_party_account_id' => (string)($payload['third_party_account_id'] ?? $payload['role_id'] ?? ($account['third_party_account_id'] ?? '')),
        ]);

        try {
            (new ProfileService(new DbUserRepository(), new SystemSettingService()))->bindStartedAccount($updated, $payload);
        } catch (Throwable $e) {
            Log::error('Failed to bind role after script started', ['account_id' => $accountId, 'error' => $e->getMessage()]);
            self::appendLogLines($accountId, ['[ERROR] 启动成功后自动绑定角色失败：' . $e->getMessage()], $sessionId);
        }
        self::appendLogLines($accountId, ['[INFO] 第三方启动成功'], $sessionId);
    }

    private static function markError(string $clientId, int $accountId, string $message, string $sessionId): void
    {
        $account = self::accounts()->findById($accountId);
        if ($account) {
            self::accounts()->updateRuntimeState((int)$account['user_id'], $accountId, [
                'status' => GameAccountService::ERROR_STATUS,
                'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
            ]);
        }
        self::appendLogLines($accountId, ['[ERROR] ' . $message], $sessionId);
        self::store()->releaseClient($clientId);
        Gateway::closeClient($clientId);
    }

    private static function markStopped(string $clientId, int $accountId): void
    {
        self::markStoppedLocally($accountId);
        self::store()->releaseClient($clientId);
        Gateway::closeClient($clientId);
    }

    private static function markStoppedLocally(int $accountId): void
    {
        $account = self::accounts()->findById($accountId);
        if (!$account) {
            return;
        }
        self::accounts()->updateRuntimeState((int)$account['user_id'], $accountId, [
            'status' => GameAccountService::STOPPED_STATUS,
            'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
            'log_session_id' => '',
        ]);
        self::accounts()->clearNormalLogLines($accountId, null);
    }

    private static function appendLogPayload(int $accountId, array $payload, string $sessionId): void
    {
        $lines = $payload['lines'] ?? null;
        if (is_array($lines)) {
            self::appendLogLines($accountId, $lines, $sessionId);
            return;
        }
        self::appendLogLines($accountId, [self::formatLogPayload($payload)], $sessionId);
    }

    private static function appendStatusPayload(int $accountId, array $payload, string $sessionId): void
    {
        $resources = $payload['resources'] ?? $payload;
        unset($resources['type'], $resources['request_id'], $resources['session_id']);
        self::appendLogLines($accountId, ['STATUS ' . self::json($resources)], $sessionId);
    }

    private static function appendEventPayload(int $accountId, array $payload): void
    {
        $events = $payload['events'] ?? null;
        if (is_array($events)) {
            self::queue()->enqueueEvents($accountId, $events);
            return;
        }
        $event = $payload['event'] ?? $payload;
        self::queue()->enqueueEvents($accountId, [$event]);
    }

    private static function appendLogLines(int $accountId, array $lines, string $sessionId): void
    {
        self::queue()->enqueueNormal($accountId, $lines, $sessionId);
    }

    private static function formatLogPayload(array $payload): string
    {
        $parts = [];
        if (!empty($payload['time'])) {
            $parts[] = (string)$payload['time'];
        }
        if (!empty($payload['level'])) {
            $parts[] = '[' . strtoupper((string)$payload['level']) . ']';
        }
        if (!empty($payload['category'])) {
            $parts[] = '[' . (string)$payload['category'] . ']';
        }
        $parts[] = (string)($payload['message'] ?? '');
        return trim(implode(' ', array_filter($parts)));
    }

    private static function closeWithError(string $clientId, string $message): void
    {
        Gateway::sendToClient($clientId, self::json([
            'type' => 'error',
            'message' => $message,
        ]));
        Gateway::closeClient($clientId);
    }

    private static function queryFromHandshake(string $data): array
    {
        $requestLine = strtok($data, "\r\n") ?: '';
        if (!preg_match('#GET\s+([^ ]+)\s+HTTP#', $requestLine, $matches)) {
            return [];
        }

        $parts = parse_url($matches[1]);
        parse_str((string)($parts['query'] ?? ''), $query);
        return is_array($query) ? $query : [];
    }

    private static function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function store(): RedisThirdPartyScriptConnectionStore
    {
        return new RedisThirdPartyScriptConnectionStore();
    }

    private static function accounts(): DbGameAccountRepository
    {
        return new DbGameAccountRepository();
    }

    private static function queue(): GameLogQueue
    {
        return new GameLogQueue();
    }
}
