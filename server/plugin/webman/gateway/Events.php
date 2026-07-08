<?php

namespace plugin\webman\gateway;

use app\repository\DbGameAccountRepository;
use app\repository\DbUserRepository;
use app\service\GameAccountAutoRestartService;
use app\service\GameAccountResourceService;
use app\service\GameAccountService;
use app\service\GameAccountTaskStateService;
use app\service\GameLogQueue;
use app\service\GatewayThirdPartyScriptRuntime;
use app\service\ProfileService;
use app\service\RedisThirdPartyScriptConnectionStore;
use app\service\SystemSettingService;
use app\support\I18n;
use GatewayWorker\Lib\Gateway;
use support\Log;
use Throwable;

class Events
{
    private const BASE_MESSAGE_BYTES = 8192;
    private const DEFAULT_TASK_STATE_BYTES = 262144;
    private const TASK_STATE_PROTOCOL_OVERHEAD_BYTES = 4096;

    public static function onWorkerStart($worker): void
    {
        Gateway::$registerAddress = '127.0.0.1:1236';
    }

    public static function onWebSocketConnect($clientId, $data): void
    {
        try {
            $query = self::queryFromHandshake($data);
            $server = self::serverFromHandshake($data);
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
                'remote_ip' => (string)($server['REMOTE_ADDR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
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
            $messageString = (string)$message;
            $messageBytes = strlen($messageString);
            if ($messageBytes > self::maxMessageBytes()) {
                self::closeWithError($clientId, 'message too large');
                return;
            }

            $payloadObject = json_decode($messageString);
            $payload = json_decode($messageString, true);
            if (!$payloadObject instanceof \stdClass) {
                self::closeWithError($clientId, 'invalid json');
                return;
            }
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
            if ($type !== 'task_state_save' && $messageBytes > self::BASE_MESSAGE_BYTES) {
                self::closeWithError($clientId, 'message too large');
                return;
            }
            if (in_array($type, ['heartbeat', 'ping', 'pong'], true)) {
                return;
            }

            $accountId = (int)($state['account_id'] ?? 0);
            if ($accountId <= 0 || !in_array((string)($state['state'] ?? ''), ['bound', 'stopping'], true)) {
                self::closeWithError($clientId, 'connection is not bound to a game account');
                return;
            }
            $sessionId = (string)($state['session_id'] ?? '');
            match ($type) {
                'started' => self::markStarted($accountId, $payload, $state),
                'log' => self::appendLogPayload($accountId, $payload, $sessionId),
                'event' => self::appendEventPayload($accountId, $payload),
                'status' => self::saveStatusPayload($accountId, $payload),
                'task_state_get' => self::sendTaskStatePayload($clientId, $accountId, $payload),
                'task_state_save' => self::saveTaskStatePayload($clientId, $accountId, $payload, $payloadObject),
                'error' => self::markError($clientId, $accountId, (string)($payload['message'] ?? '第三方脚本返回异常'), $sessionId),
                'stopped' => self::markStopped($clientId, $accountId, (string)($state['state'] ?? ''), $sessionId),
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
        if (!$account || !in_array((string)($account['status'] ?? ''), [GameAccountService::STARTING_STATUS, GameAccountService::RUNNING_STATUS, GameAccountService::RECONNECTING_STATUS], true)) {
            return;
        }

        if ((int)($account['desired_running'] ?? 0) === 1) {
            self::autoRestarter()->scheduleReconnect($accountId, '第三方脚本连接断开', (string)($state['session_id'] ?? ''));
            return;
        }

        self::accounts()->updateRuntimeState((int)$account['user_id'], $accountId, [
            'status' => GameAccountService::ERROR_STATUS,
            'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
        ]);
        self::resources()->clear($accountId);
        self::appendLogLines($accountId, ['[ERROR] 第三方脚本连接断开'], (string)($state['session_id'] ?? ''));
    }

    private static function markStarted(int $accountId, array $payload, array $state): void
    {
        $sessionId = (string)($state['session_id'] ?? '');
        $trace = self::startedTrace($payload, $state);
        Log::info('Third-party started received', array_merge([
            'account_id' => $accountId,
            'client_id' => (string)($state['client_id'] ?? ''),
        ], $trace));
        self::appendLogLines($accountId, ['[INFO] 收到第三方 started：' . self::json($trace)], $sessionId);

        if (!self::startedContextMatches($payload, $state)) {
            Log::warning('Third-party started context mismatch', array_merge([
                'account_id' => $accountId,
                'client_id' => (string)($state['client_id'] ?? ''),
            ], $trace));
            self::appendLogLines($accountId, ['[ERROR] 第三方 started 回包与当前启动会话不匹配，已忽略：' . self::json($trace)], $sessionId);
            return;
        }

        $account = self::accounts()->findById($accountId);
        if (!$account) {
            return;
        }
        if (!self::canAcceptStarted($account)) {
            self::appendLogLines($accountId, ['[WARN] 第三方 started 回包已过期或账号不再允许运行，已忽略'], $sessionId);
            return;
        }

        $roleId = self::startedRoleId($account, $payload);

        $updated = self::accounts()->updateRuntimeState((int)$account['user_id'], $accountId, [
            'status' => GameAccountService::RUNNING_STATUS,
            'sync_status' => 'synced',
            'display_name' => (string)($payload['display_name'] ?? ($account['display_name'] ?? '')),
            'third_party_account_id' => $roleId,
            'desired_running' => 1,
            'auto_restart_attempts' => 0,
            'auto_restart_next_at' => null,
            'auto_restart_last_error' => '',
        ]);

        try {
            (new ProfileService(new DbUserRepository(), new SystemSettingService()))->bindStartedAccount($updated, array_merge($payload, [
                'role_id' => $roleId,
            ]));
        } catch (Throwable $e) {
            Log::error('Failed to bind role after script started', ['account_id' => $accountId, 'error' => $e->getMessage()]);
            self::appendLogLines($accountId, ['[ERROR] 启动成功后自动绑定角色失败：' . $e->getMessage()], $sessionId);
        }
        self::appendLogLines($accountId, ['[INFO] 第三方启动成功'], $sessionId);
    }

    private static function startedContextMatches(array $payload, array $state): bool
    {
        $expectedRequestId = (string)($state['request_id'] ?? '');
        $expectedSessionId = (string)($state['session_id'] ?? '');
        $actualRequestId = (string)($payload['request_id'] ?? '');
        $actualSessionId = (string)($payload['session_id'] ?? '');

        return $expectedRequestId !== ''
            && $expectedSessionId !== ''
            && hash_equals($expectedRequestId, $actualRequestId)
            && hash_equals($expectedSessionId, $actualSessionId);
    }

    private static function startedTrace(array $payload, array $state): array
    {
        return [
            'expected_request_id' => (string)($state['request_id'] ?? ''),
            'actual_request_id' => (string)($payload['request_id'] ?? ''),
            'expected_session_id' => (string)($state['session_id'] ?? ''),
            'actual_session_id' => (string)($payload['session_id'] ?? ''),
            'role_id' => (string)($payload['role_id'] ?? ''),
        ];
    }

    private static function canAcceptStarted(array $account): bool
    {
        if (!in_array((string)($account['status'] ?? ''), [GameAccountService::STARTING_STATUS, GameAccountService::RECONNECTING_STATUS], true)) {
            return false;
        }
        if ((int)($account['desired_running'] ?? 0) !== 1) {
            return false;
        }

        $expireTime = (string)($account['expire_time'] ?? '');
        if ($expireTime === '') {
            return false;
        }
        $expireTimestamp = strtotime($expireTime);
        return $expireTimestamp !== false && $expireTimestamp > time();
    }

    private static function startedRoleId(array $account, array $payload): string
    {
        $roleId = trim((string)($payload['role_id'] ?? ''));
        if ($roleId !== '') {
            return $roleId;
        }

        return trim((string)($account['game_username'] ?? ''));
    }

    private static function markError(string $clientId, int $accountId, string $message, string $sessionId): void
    {
        $account = self::accounts()->findById($accountId);
        if ($account) {
            self::accounts()->updateRuntimeState((int)$account['user_id'], $accountId, [
                'status' => GameAccountService::ERROR_STATUS,
                'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
                'desired_running' => 0,
                'auto_restart_attempts' => 0,
                'auto_restart_next_at' => null,
                'auto_restart_last_error' => '',
            ]);
        }
        self::resources()->clear($accountId);
        self::appendLogLines($accountId, ['[ERROR] ' . $message], $sessionId);
        self::store()->releaseClient($clientId);
        Gateway::closeClient($clientId);
    }

    private static function markStopped(string $clientId, int $accountId, string $connectionState, string $sessionId): void
    {
        self::store()->releaseClient($clientId);
        Gateway::closeClient($clientId);
        if ($connectionState === 'stopping') {
            self::markStoppedLocally($accountId);
            return;
        }

        $account = self::accounts()->findById($accountId);
        if ($account && (int)($account['desired_running'] ?? 0) === 1) {
            self::autoRestarter()->scheduleReconnect($accountId, '第三方脚本异常停止', $sessionId);
            return;
        }

        self::markStoppedLocally($accountId);
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
            'desired_running' => 0,
            'auto_restart_attempts' => 0,
            'auto_restart_next_at' => null,
            'auto_restart_last_error' => '',
        ]);
        self::accounts()->clearNormalLogLines($accountId, null);
        self::resources()->clear($accountId);
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

    private static function saveStatusPayload(int $accountId, array $payload): void
    {
        $result = self::resources()->saveStatusPayload($accountId, $payload);
        if (($result['unknown_keys'] ?? []) !== []) {
            Log::warning('Third-party status contains unknown resource fields', [
                'account_id' => $accountId,
                'unknown_keys' => $result['unknown_keys'],
            ]);
        }
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

    private static function sendTaskStatePayload(string $clientId, int $accountId, array $payload): void
    {
        if (array_key_exists('account_id', $payload)) {
            self::closeWithError($clientId, 'account_id is not accepted in task state messages');
            return;
        }

        $result = self::taskStates()->get($accountId);
        Gateway::sendToClient($clientId, self::json([
            'type' => 'task_state',
            'request_id' => (string)($payload['request_id'] ?? ''),
            'exists' => (bool)$result['exists'],
            'state' => $result['state'],
            'saved_at' => $result['saved_at'],
        ]));
    }

    private static function saveTaskStatePayload(string $clientId, int $accountId, array $payload, \stdClass $payloadObject): void
    {
        if (array_key_exists('account_id', $payload)) {
            self::closeWithError($clientId, 'account_id is not accepted in task state messages');
            return;
        }

        if (!property_exists($payloadObject, 'state') || !$payloadObject->state instanceof \stdClass) {
            self::closeWithError($clientId, 'task state must be a json object');
            return;
        }

        $result = self::taskStates()->enqueueSave($accountId, $payloadObject->state);
        Gateway::sendToClient($clientId, self::json([
            'type' => 'task_state_queued',
            'request_id' => (string)($payload['request_id'] ?? ''),
            'queued_at' => $result['queued_at'],
            'bytes' => (int)$result['bytes'],
        ]));
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

    private static function queryFromHandshake(mixed $data): array
    {
        if (is_array($data)) {
            $query = $data['get'] ?? [];
            if (is_array($query) && $query !== []) {
                return $query;
            }

            $server = $data['server'] ?? [];
            if (is_array($server) && isset($server['QUERY_STRING'])) {
                parse_str((string)$server['QUERY_STRING'], $parsed);
                return is_array($parsed) ? $parsed : [];
            }

            return [];
        }

        if (is_object($data)) {
            if (method_exists($data, 'get')) {
                $query = $data->get();
                if (is_array($query)) {
                    return $query;
                }
            }
            if (method_exists($data, 'queryString')) {
                parse_str((string)$data->queryString(), $query);
                return is_array($query) ? $query : [];
            }

            return [];
        }

        return self::queryFromHandshakeString((string)$data);
    }

    private static function serverFromHandshake(mixed $data): array
    {
        if (is_array($data) && is_array($data['server'] ?? null)) {
            return $data['server'];
        }

        return [];
    }

    private static function queryFromHandshakeString(string $data): array
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

    private static function maxMessageBytes(): int
    {
        return max(self::BASE_MESSAGE_BYTES, self::taskStateMaxBytes() + self::TASK_STATE_PROTOCOL_OVERHEAD_BYTES);
    }

    private static function taskStateMaxBytes(): int
    {
        return max(1, (int)app_env('GAME_TASK_STATE_MAX_BYTES', (string)self::DEFAULT_TASK_STATE_BYTES));
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

    private static function taskStates(): GameAccountTaskStateService
    {
        return new GameAccountTaskStateService(self::accounts(), self::taskStateMaxBytes());
    }

    private static function resources(): GameAccountResourceService
    {
        return new GameAccountResourceService();
    }

    private static function autoRestarter(): GameAccountAutoRestartService
    {
        $store = self::store();
        $config = (new SystemSettingService())->thirdPartyConfig();
        return new GameAccountAutoRestartService(
            self::accounts(),
            new GatewayThirdPartyScriptRuntime($store),
            $store,
            self::queue(),
            (string)($config['credential_key'] ?? '')
        );
    }
}
