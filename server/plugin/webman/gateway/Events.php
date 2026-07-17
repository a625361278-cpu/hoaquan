<?php

namespace plugin\webman\gateway;

use app\repository\DbGameAccountRepository;
use app\repository\DbUserRepository;
use app\service\GameAccountAutoRestartService;
use app\service\GameAccountLoginMethod;
use app\service\GameAccountLoginValidationService;
use app\service\GameAccountResourceService;
use app\service\GameAccountService;
use app\service\GameAccountTaskStateService;
use app\service\GameLogMessage;
use app\service\GameLogNormalizer;
use app\service\GameLogQueue;
use app\service\GatewayThirdPartyScriptRuntime;
use app\service\InviteRewardService;
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

            $peerIp = (string)($server['REMOTE_ADDR'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
            $metadata = [
                'remote_ip' => self::forwardedClientIp($server, $peerIp),
                'peer_ip' => $peerIp,
                'peer_port' => (int)($server['REMOTE_PORT'] ?? ($_SERVER['REMOTE_PORT'] ?? 0)),
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
            Log::info('Third-party script websocket connected', self::connectionTrace($clientId, $state));
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
            $jsonErrorCode = json_last_error();
            $jsonErrorMessage = json_last_error_msg();
            $payload = json_decode($messageString, true);
            if (!$payloadObject instanceof \stdClass) {
                $invalidMessageFile = self::quarantineInvalidMessage($clientId, $messageString);
                Log::warning('Third-party script websocket invalid message', array_merge(
                    self::connectionTrace($clientId, self::store()->connection($clientId) ?? []),
                    self::invalidMessageDiagnostics($messageString, $jsonErrorCode, $jsonErrorMessage),
                    ['invalid_message_file' => $invalidMessageFile]
                ));
                self::closeWithError(
                    $clientId,
                    $jsonErrorCode === JSON_ERROR_NONE ? 'json root must be object' : 'invalid json'
                );
                return;
            }
            if (!is_array($payload)) {
                self::closeWithError($clientId, 'invalid json');
                return;
            }

            $type = (string)($payload['type'] ?? 'log');
            $state = self::store()->heartbeat($clientId, [
                'script_version' => $payload['script_version'] ?? null,
                'message_type' => $type,
                'message_bytes' => $messageBytes,
            ]);
            if (!$state) {
                self::closeWithError($clientId, 'connection state missing');
                return;
            }

            if ($type !== 'task_state_save' && $messageBytes > self::BASE_MESSAGE_BYTES) {
                self::closeWithError($clientId, 'message too large');
                return;
            }
            if (in_array($type, ['heartbeat', 'ping', 'pong'], true)) {
                return;
            }

            if ((string)($state['state'] ?? '') === 'validating') {
                if ($type !== 'login' || !self::validLoginValidationPayload($payload)) {
                    self::loginValidations()->failProtocol(
                        (string)($state['validation_id'] ?? ''),
                        '第三方登录验证响应不符合协议'
                    );
                    self::closeWithError($clientId, 'invalid login validation response');
                    return;
                }
                if (!self::loginValidations()->completeFromThirdParty($clientId, $payload, $state)) {
                    self::closeWithError($clientId, 'login validation context mismatch');
                }
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
                'error' => self::markError($clientId, $accountId, (string)($payload['message'] ?? '游戏运行异常'), $sessionId),
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
        if (!$state) {
            Log::warning('Third-party script websocket closed without connection state', [
                'client_id' => $clientId,
            ]);
            return;
        }

        $trace = self::connectionTrace($clientId, $state);
        if ((string)($state['state'] ?? '') === 'validating') {
            self::loginValidations()->failProtocol(
                (string)($state['validation_id'] ?? ''),
                '第三方登录验证连接已断开'
            );
            Log::warning('Third-party login validation websocket closed', $trace);
            return;
        }
        if ((int)($state['account_id'] ?? 0) > 0) {
            Log::warning('Third-party script bound websocket closed', $trace);
        } else {
            Log::info('Third-party script idle websocket closed', $trace);
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
            self::autoRestarter()->scheduleReconnect($accountId, 'client.logs.system.runtime_connection_closed_reconnecting', (string)($state['session_id'] ?? ''));
            return;
        }

        self::accounts()->updateRuntimeState((int)$account['user_id'], $accountId, [
            'status' => GameAccountService::ERROR_STATUS,
            'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
        ]);
        self::resources()->clear($accountId);
        self::appendLogLines($accountId, [GameLogMessage::localized('ERROR', 'client.logs.system.runtime_connection_closed')], (string)($state['session_id'] ?? ''));
    }

    private static function markStarted(int $accountId, array $payload, array $state): void
    {
        $sessionId = (string)($state['session_id'] ?? '');

        if (!self::startedContextMatches($payload, $state)) {
            Log::warning('Third-party started context mismatch', array_merge([
                'account_id' => $accountId,
                'client_id' => (string)($state['client_id'] ?? ''),
            ], self::startedContextTrace($payload, $state)));
            self::appendLogLines($accountId, [GameLogMessage::localized('WARN', 'client.logs.system.start_confirmation_expired')], $sessionId);
            return;
        }

        $account = self::accounts()->findById($accountId);
        if (!$account) {
            return;
        }
        if (!self::canAcceptStarted($account)) {
            self::appendLogLines($accountId, [GameLogMessage::localized('WARN', 'client.logs.system.start_confirmation_expired')], $sessionId);
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

        $roleBound = false;
        try {
            (new ProfileService(new DbUserRepository(), new SystemSettingService()))->bindStartedAccount($updated, array_merge($payload, [
                'role_id' => $roleId,
            ]));
            $roleBound = true;
        } catch (Throwable $e) {
            Log::error('Failed to bind role after script started', ['account_id' => $accountId, 'error' => $e->getMessage()]);
            self::appendLogLines($accountId, [GameLogMessage::localized('ERROR', 'client.logs.system.role_bind_failed', [
                'error' => $e->getMessage(),
            ])], $sessionId);
        }
        if ($roleBound) {
            $resources = self::resources()->resourcesForAccount($accountId);
            self::tryGrantInviteReward($accountId, $resources['level'] ?? null, 'started');
        }
        self::appendLogLines($accountId, [GameLogMessage::localized('INFO', 'client.logs.system.game_started')], $sessionId);
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

    private static function startedContextTrace(array $payload, array $state): array
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

        $loginMethod = (int)($account['login_method'] ?? GameAccountLoginMethod::ACCOUNT_PASSWORD);
        return trim((string)($loginMethod === GameAccountLoginMethod::ACCOUNT_PASSWORD
            ? ($account['game_username'] ?? '')
            : ($account['game_uid'] ?? '')));
    }

    private static function markError(string $clientId, int $accountId, string $message, string $sessionId): void
    {
        Log::warning('Third-party script websocket closing after client error', [
            'client_id' => $clientId,
            'account_id' => $accountId,
            'reason' => 'client_reported_error',
        ]);
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
        Log::info('Third-party script websocket closing after client stopped', [
            'client_id' => $clientId,
            'account_id' => $accountId,
            'connection_state' => $connectionState,
            'reason' => 'client_reported_stopped',
        ]);
        self::store()->releaseClient($clientId);
        Gateway::closeClient($clientId);
        if ($connectionState === 'stopping') {
            self::markStoppedLocally($accountId);
            return;
        }

        $account = self::accounts()->findById($accountId);
        if ($account && (int)($account['desired_running'] ?? 0) === 1) {
            self::autoRestarter()->scheduleReconnect($accountId, 'client.logs.system.runtime_stopped_reconnecting', $sessionId);
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
        $reportedLevel = self::reportedLevel($payload);
        $result = self::resources()->saveStatusPayload($accountId, self::withoutStructuredLevel($payload));
        if (($result['unknown_keys'] ?? []) !== []) {
            Log::warning('Third-party status contains unknown resource fields', [
                'account_id' => $accountId,
                'unknown_keys' => $result['unknown_keys'],
            ]);
        }
        self::tryGrantInviteReward($accountId, $reportedLevel, 'status');
    }

    private static function reportedLevel(array $payload): mixed
    {
        $source = $payload['resources'] ?? $payload;
        return is_array($source) && array_key_exists('level', $source) ? $source['level'] : null;
    }

    private static function withoutStructuredLevel(array $payload): array
    {
        if (isset($payload['resources']) && is_array($payload['resources'])) {
            if (isset($payload['resources']['level'])
                && (is_array($payload['resources']['level']) || is_object($payload['resources']['level']))) {
                unset($payload['resources']['level']);
            }
            return $payload;
        }
        if (isset($payload['level']) && (is_array($payload['level']) || is_object($payload['level']))) {
            unset($payload['level']);
        }
        return $payload;
    }

    private static function tryGrantInviteReward(int $accountId, mixed $reportedLevel, string $source): void
    {
        try {
            $result = (new InviteRewardService())->tryGrantForAccountLevel($accountId, $reportedLevel);
            if (($result['rewarded'] ?? false) === true) {
                Log::info('Invite reward granted from real game status', [
                    'account_id' => $accountId,
                    'source' => $source,
                    'level' => $result['level'],
                    'min_level' => $result['min_level'],
                ]);
                return;
            }
            if (($result['reason'] ?? '') === 'level_invalid') {
                Log::warning('Third-party status contains invalid role level', [
                    'account_id' => $accountId,
                    'source' => $source,
                    'level_type' => get_debug_type($reportedLevel),
                    'level_value' => is_scalar($reportedLevel) ? substr((string)$reportedLevel, 0, 64) : '',
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Failed to evaluate invite reward', [
                'account_id' => $accountId,
                'source' => $source,
                'error' => $e->getMessage(),
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
        return (new GameLogNormalizer())->formatStructuredLog($payload);
    }

    private static function closeWithError(string $clientId, string $message): void
    {
        $state = self::store()->connection($clientId);
        if (($state['state'] ?? '') === 'validating') {
            self::loginValidations()->failProtocol(
                (string)($state['validation_id'] ?? ''),
                '第三方登录验证响应异常'
            );
        }
        Log::warning('Third-party script websocket closing with server error', array_merge(
            self::connectionTrace($clientId, $state ?? []),
            ['reason' => $message]
        ));
        Gateway::sendToClient($clientId, self::json([
            'type' => 'error',
            'message' => $message,
        ]));
        Gateway::closeClient($clientId);
    }

    private static function connectionTrace(string $clientId, array $state): array
    {
        $now = time();
        $connectedAt = (int)($state['connected_at'] ?? 0);
        $boundAt = (int)($state['bound_at'] ?? 0);
        $lastSeen = (int)($state['last_seen'] ?? 0);
        $lastMessageAt = (int)($state['last_message_at'] ?? 0);
        $lastHeartbeatAt = (int)($state['last_heartbeat_at'] ?? 0);

        return [
            'client_id' => $clientId,
            'remote_ip' => (string)($state['remote_ip'] ?? ''),
            'peer_ip' => (string)($state['peer_ip'] ?? ''),
            'peer_port' => (int)($state['peer_port'] ?? 0),
            'state' => (string)($state['state'] ?? ''),
            'account_id' => (int)($state['account_id'] ?? 0),
            'request_id' => (string)($state['request_id'] ?? ''),
            'validation_id' => (string)($state['validation_id'] ?? ''),
            'session_id' => (string)($state['session_id'] ?? ''),
            'connected_seconds' => $connectedAt > 0 ? max(0, $now - $connectedAt) : null,
            'bound_seconds' => $boundAt > 0 ? max(0, $now - $boundAt) : null,
            'last_seen_seconds' => $lastSeen > 0 ? max(0, $now - $lastSeen) : null,
            'last_message_seconds' => $lastMessageAt > 0 ? max(0, $now - $lastMessageAt) : null,
            'last_heartbeat_seconds' => $lastHeartbeatAt > 0 ? max(0, $now - $lastHeartbeatAt) : null,
            'last_message_type' => (string)($state['last_message_type'] ?? ''),
            'last_message_bytes' => (int)($state['last_message_bytes'] ?? 0),
            'message_count' => (int)($state['message_count'] ?? 0),
            'heartbeat_count' => (int)($state['heartbeat_count'] ?? 0),
            'script_version' => (string)($state['script_version'] ?? ''),
        ];
    }

    private static function forwardedClientIp(array $server, string $fallback): string
    {
        $forwardedFor = trim((string)($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor === '') {
            return $fallback;
        }

        $candidate = trim(explode(',', $forwardedFor, 2)[0]);
        return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : $fallback;
    }

    private static function invalidMessageDiagnostics(string $message, int $jsonErrorCode, string $jsonErrorMessage): array
    {
        $trimmed = trim($message);
        return [
            'invalid_message_bytes' => strlen($message),
            'invalid_message_trimmed_bytes' => strlen($trimmed),
            'invalid_message_sha256' => hash('sha256', $message),
            'invalid_message_json_error_code' => $jsonErrorCode,
            'invalid_message_json_error' => $jsonErrorMessage,
            'invalid_message_utf8' => preg_match('//u', $message) === 1,
            'invalid_message_starts_object' => str_starts_with($trimmed, '{'),
            'invalid_message_ends_object' => str_ends_with($trimmed, '}'),
            'invalid_message_prefix_hex' => bin2hex(substr($message, 0, 16)),
            'invalid_message_suffix_hex' => bin2hex(substr($message, -16)),
        ];
    }

    private static function quarantineInvalidMessage(string $clientId, string $message): string
    {
        $directory = runtime_path('diagnostics/third-party-invalid');
        try {
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('无法创建非法消息隔离目录');
            }
            chmod($directory, 0700);

            $safeClientId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $clientId) ?: 'unknown';
            $hash = hash('sha256', $message);
            $filename = date('Ymd-His') . '-' . $safeClientId . '-' . substr($hash, 0, 16) . '.bin';
            $path = $directory . DIRECTORY_SEPARATOR . $filename;
            $written = file_put_contents($path, $message, LOCK_EX);
            if ($written === false || $written !== strlen($message)) {
                throw new \RuntimeException('非法消息原始数据写入不完整');
            }
            chmod($path, 0600);
            self::pruneInvalidMessages($directory, time() - 7 * 86400);
            return $path;
        } catch (Throwable $e) {
            Log::error('Third-party invalid message quarantine failed', [
                'client_id' => $clientId,
                'message_bytes' => strlen($message),
                'message_sha256' => hash('sha256', $message),
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    private static function pruneInvalidMessages(string $directory, int $olderThan): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.bin') ?: [] as $path) {
            $modifiedAt = filemtime($path);
            if ($modifiedAt !== false && $modifiedAt < $olderThan && !unlink($path)) {
                Log::warning('Third-party invalid message quarantine cleanup failed', [
                    'path' => $path,
                ]);
            }
        }
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

    private static function validLoginValidationPayload(array $payload): bool
    {
        if (!array_key_exists('request_id', $payload)
            || !is_string($payload['request_id'])
            || !array_key_exists('session_id', $payload)
            || !is_string($payload['session_id'])
            || !array_key_exists('code', $payload)
            || !is_int($payload['code'])
            || !in_array($payload['code'], [0, 1], true)
            || !array_key_exists('msg', $payload)
            || !is_string($payload['msg'])) {
            return false;
        }
        return $payload['code'] === 0
            || (array_key_exists('server_name', $payload)
                && is_string($payload['server_name'])
                && trim($payload['server_name']) !== '');
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

    private static function loginValidations(): GameAccountLoginValidationService
    {
        $settings = new SystemSettingService();
        $config = $settings->thirdPartyConfig();
        $config['max_accounts_per_user'] = $settings->gameAccountMaxCount();
        $store = self::store();
        return new GameAccountLoginValidationService(
            self::accounts(),
            $config,
            I18n::DEFAULT_LOCALE,
            new GatewayThirdPartyScriptRuntime($store),
            new \app\service\RedisGameAccountLoginValidationStore()
        );
    }
}
