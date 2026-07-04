<?php

namespace app\process;

use app\repository\DbGameAccountRepository;
use app\repository\DbUserRepository;
use app\repository\GameAccountRepositoryInterface;
use app\service\CredentialCipher;
use app\service\GameAccountLogService;
use app\service\GameAccountService;
use app\service\ProfileService;
use app\service\RedisThirdPartyCommandQueue;
use app\service\SystemSettingService;
use app\service\ThirdPartyCommandQueueInterface;
use app\support\I18n;
use RuntimeException;
use support\Log;
use Throwable;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

class ThirdPartyConnectionWorker
{
    private const COMMAND_INTERVAL = 1;
    private const STATE_INTERVAL = 30;
    private const MAX_RECONNECT_ATTEMPTS = 3;
    private const DEFAULT_CONNECTION_CAPACITY = 10;

    /** @var array<string, object> */
    private array $connections = [];
    /** @var array<string, bool> */
    private array $connectedSlots = [];
    /** @var array<string, string> */
    private array $slotUrls = [];
    /** @var array<string, array<int, bool>> */
    private array $slotAccounts = [];
    /** @var array<int, string> */
    private array $accountSlots = [];
    /** @var array<int, bool> */
    private array $stopping = [];
    /** @var array<string, bool> */
    private array $desiredSlots = [];
    /** @var array<string, bool> */
    private array $stoppingSlots = [];
    /** @var array<string, int> */
    private array $reconnectAttempts = [];
    /** @var array<int, string> */
    private array $requestIds = [];
    private ?object $startedAccountBinder;
    private $connectionFactory;

    public function __construct(
        private ?GameAccountRepositoryInterface $accounts = null,
        private ?ThirdPartyCommandQueueInterface $queue = null,
        private ?SystemSettingService $settings = null,
        private string $locale = I18n::DEFAULT_LOCALE,
        ?object $startedAccountBinder = null,
        ?callable $connectionFactory = null
    )
    {
        $this->accounts ??= new DbGameAccountRepository();
        $this->queue ??= new RedisThirdPartyCommandQueue();
        $this->settings ??= new SystemSettingService();
        $this->locale = I18n::normalizeLocale($this->locale);
        $this->startedAccountBinder = $startedAccountBinder
            ?? ($this->accounts instanceof DbGameAccountRepository
                ? new ProfileService(new DbUserRepository(), $this->settings, $this->locale)
                : null);
        $this->connectionFactory = $connectionFactory
            ?? static fn (string $url): object => new AsyncTcpConnection($url);
    }

    public function onWorkerStart(): void
    {
        $this->recoverRunningAccounts();
        Timer::add(self::COMMAND_INTERVAL, fn () => $this->pollCommands());
        Timer::add(self::STATE_INTERVAL, fn () => $this->refreshConnectionStates());
    }

    public function pollCommands(): void
    {
        while ($command = $this->queue->popCommand()) {
            $this->handleCommand($command);
        }
    }

    public function handleCommand(array $command): void
    {
        $action = (string)($command['action'] ?? '');
        if (in_array($action, ['start_slot', 'stop_slot', 'start_all_slots', 'stop_all_slots'], true)) {
            $this->handleSlotCommand($command);
            return;
        }

        $accountId = (int)($command['account_id'] ?? 0);
        if ($accountId <= 0 || !in_array($action, ['start', 'stop'], true)) {
            Log::warning('Invalid third-party websocket command', ['command' => $command]);
            return;
        }

        if ($action === 'stop') {
            $this->stopAccount($accountId, (string)($command['command_id'] ?? ''));
            return;
        }

        $this->connectAccount($accountId, (string)($command['command_id'] ?? bin2hex(random_bytes(16))));
    }

    private function handleSlotCommand(array $command): void
    {
        $action = (string)($command['action'] ?? '');
        if ($action === 'start_all_slots') {
            $this->startAllConfiguredSlots();
            return;
        }
        if ($action === 'stop_all_slots') {
            $this->stopAllConfiguredSlots();
            return;
        }

        $slotId = (string)($command['slot_id'] ?? '');
        if (!$this->isValidSlotId($slotId)) {
            Log::warning('Invalid third-party websocket slot command', ['command' => $command]);
            return;
        }

        if ($action === 'start_slot') {
            $this->startConfiguredSlot($slotId);
            return;
        }
        if ($action === 'stop_slot') {
            $this->stopConfiguredSlot($slotId);
            return;
        }

        Log::warning('Unknown third-party websocket slot command', ['command' => $command]);
    }

    public function recoverRunningAccounts(): void
    {
        foreach ($this->accounts->listByStatuses([GameAccountService::STARTING_STATUS, GameAccountService::RUNNING_STATUS]) as $account) {
            $accountId = (int)($account['id'] ?? 0);
            if ($accountId > 0) {
                $this->queue->enqueueStart($accountId);
            }
        }
    }

    private function startAllConfiguredSlots(): void
    {
        foreach (array_keys($this->configuredSlotUrls()) as $slotId) {
            $this->startConfiguredSlot($slotId);
        }
    }

    private function stopAllConfiguredSlots(): void
    {
        $slotIds = array_unique(array_merge(array_keys($this->slotUrls), array_keys($this->configuredSlotUrls())));
        sort($slotIds);
        foreach ($slotIds as $slotId) {
            $this->stopConfiguredSlot($slotId);
        }
    }

    private function startConfiguredSlot(string $slotId): void
    {
        $config = $this->settings->thirdPartyConfig();
        if (empty($config['enabled'])) {
            $this->writeSlotState($slotId, 'error', I18n::t('api.third_party.disabled', [], $this->locale));
            return;
        }

        $slots = $this->configuredSlotUrls($config);
        if ($slots === []) {
            $this->writeSlotState($slotId, 'error', I18n::t('api.third_party.websocket_unconfigured', [], $this->locale));
            return;
        }
        if (!isset($slots[$slotId])) {
            $this->writeSlotState($slotId, 'error', '第三方WebSocket连接槽位不存在');
            return;
        }

        $this->slotUrls[$slotId] = $slots[$slotId];
        $this->slotAccounts[$slotId] ??= [];
        $this->desiredSlots[$slotId] = true;
        unset($this->stoppingSlots[$slotId]);

        if (($this->connectedSlots[$slotId] ?? false) === true) {
            $this->writeSlotState($slotId, 'connected');
            return;
        }

        try {
            $this->writeSlotState($slotId, 'connecting');
            $this->ensureSlotConnection($slotId);
        } catch (Throwable $e) {
            $this->writeSlotState($slotId, 'error', $e->getMessage());
        }
    }

    private function stopConfiguredSlot(string $slotId): void
    {
        $this->stoppingSlots[$slotId] = true;
        unset($this->desiredSlots[$slotId]);
        $this->writeSlotState($slotId, 'stopping');

        foreach (array_keys($this->slotAccounts[$slotId] ?? []) as $accountId) {
            $this->forceStopAccountOnSlot((int)$accountId, $slotId);
        }

        if (isset($this->connections[$slotId]) && method_exists($this->connections[$slotId], 'close')) {
            $this->connections[$slotId]->close();
            return;
        }

        unset($this->connections[$slotId], $this->connectedSlots[$slotId], $this->stoppingSlots[$slotId]);
        $this->writeSlotState($slotId, 'disconnected');
    }

    private function forceStopAccountOnSlot(int $accountId, string $slotId): void
    {
        if (isset($this->connections[$slotId]) && ($this->connectedSlots[$slotId] ?? false)) {
            $this->sendJson($this->connections[$slotId], [
                'type' => 'stop',
                'request_id' => bin2hex(random_bytes(16)),
                'account_id' => $accountId,
            ]);
        }

        $this->markStopped($accountId, '后台强制关闭第三方连接');
    }

    public function handleThirdPartyMessage(string $slotId, string $data): void
    {
        $payload = json_decode($data, true);
        if (!is_array($payload)) {
            Log::warning('Invalid third-party websocket JSON', ['slot_id' => $slotId, 'payload' => $data]);
            return;
        }

        $accountId = (int)($payload['account_id'] ?? 0);
        if ($accountId <= 0) {
            Log::warning('Third-party websocket message missing account_id', ['slot_id' => $slotId, 'payload' => $payload]);
            return;
        }

        if (($this->accountSlots[$accountId] ?? null) !== $slotId) {
            Log::warning('Third-party websocket account is not assigned to this connection', [
                'slot_id' => $slotId,
                'account_id' => $accountId,
                'assigned_slot_id' => $this->accountSlots[$accountId] ?? null,
            ]);
            return;
        }

        match ((string)($payload['type'] ?? 'log')) {
            'started' => $this->markStarted($accountId, $payload),
            'log' => $this->appendLog($accountId, $this->formatLogPayload($payload)),
            'status' => $this->appendLog($accountId, 'STATUS ' . json_encode($payload['resources'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'error' => $this->markError($accountId, (string)($payload['message'] ?? '第三方返回异常')),
            'stopped' => $this->markStopped($accountId, (string)($payload['message'] ?? '第三方已停止')),
            default => $this->appendLog($accountId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        };
    }

    private function connectAccount(int $accountId, string $requestId): void
    {
        if (isset($this->accountSlots[$accountId])) {
            return;
        }

        $account = $this->accounts->findById($accountId);
        if (!$account) {
            Log::warning('Third-party websocket account not found', ['account_id' => $accountId]);
            return;
        }

        $config = $this->settings->thirdPartyConfig();
        if (empty($config['enabled'])) {
            $this->markError($accountId, I18n::t('api.third_party.disabled', [], $this->locale));
            return;
        }
        if ($this->wsUrls($config) === []) {
            $this->markError($accountId, I18n::t('api.third_party.websocket_unconfigured', [], $this->locale));
            return;
        }

        try {
            $slotId = $this->allocateSlot($accountId, $config);
            $this->requestIds[$accountId] = $requestId;
            $hadConnection = isset($this->connections[$slotId]);
            $this->ensureSlotConnection($slotId);
            if ($hadConnection && ($this->connectedSlots[$slotId] ?? false)) {
                $this->sendStartForAccount($slotId, $accountId);
            }
        } catch (Throwable $e) {
            $this->markError($accountId, $e->getMessage());
        }
    }

    private function stopAccount(int $accountId, string $requestId): void
    {
        $this->stopping[$accountId] = true;
        $slotId = $this->accountSlots[$accountId] ?? null;
        if ($slotId && isset($this->connections[$slotId]) && ($this->connectedSlots[$slotId] ?? false)) {
            $this->sendJson($this->connections[$slotId], [
                'type' => 'stop',
                'request_id' => $requestId ?: bin2hex(random_bytes(16)),
                'account_id' => $accountId,
            ]);
            $this->queue->writeAccountState($accountId, ['state' => 'stopping', 'slot_id' => $slotId, 'updated_at' => time()]);
            return;
        }

        $this->releaseAccount($accountId);
    }

    private function allocateSlot(int $accountId, array $config): string
    {
        $capacity = max(1, (int)($config['ws_connection_capacity'] ?? self::DEFAULT_CONNECTION_CAPACITY));
        foreach ($this->configuredSlotUrls($config) as $slotId => $url) {
            $this->slotUrls[$slotId] = $url;
            $this->slotAccounts[$slotId] ??= [];
        }

        foreach (array_keys($this->slotUrls) as $slotId) {
            if (count($this->slotAccounts[$slotId] ?? []) < $capacity) {
                return $this->assignAccountToSlot($accountId, $slotId);
            }
        }

        foreach ($this->wsUrls($config) as $index => $url) {
            $slotId = 'slot-' . ($index + 1);
            if (!isset($this->slotUrls[$slotId])) {
                $this->slotUrls[$slotId] = $url;
                $this->slotAccounts[$slotId] = [];
            }
            if (count($this->slotAccounts[$slotId]) < $capacity) {
                return $this->assignAccountToSlot($accountId, $slotId);
            }
        }

        throw new RuntimeException(I18n::t('api.third_party.websocket_capacity_full', [], $this->locale));
    }

    private function assignAccountToSlot(int $accountId, string $slotId): string
    {
        $this->slotAccounts[$slotId][$accountId] = true;
        $this->accountSlots[$accountId] = $slotId;
        unset($this->stopping[$accountId]);
        $this->writeSlotState($slotId, ($this->connectedSlots[$slotId] ?? false) ? 'connected' : 'connecting');
        return $slotId;
    }

    private function ensureSlotConnection(string $slotId): void
    {
        if (isset($this->connections[$slotId])) {
            return;
        }

        $url = $this->slotUrls[$slotId] ?? '';
        if ($url === '') {
            throw new RuntimeException('第三方WebSocket连接槽位地址缺失');
        }

        $factory = $this->connectionFactory;
        $connection = $factory($url, $slotId);
        $this->connections[$slotId] = $connection;
        $this->connectedSlots[$slotId] = false;
        $this->writeSlotState($slotId, 'connecting');

        $connection->onConnect = function ($connection) use ($slotId): void {
            $this->connections[$slotId] = $connection;
            $this->connectedSlots[$slotId] = true;
            $this->reconnectAttempts[$slotId] = 0;
            $this->writeSlotState($slotId, 'connected');
            $this->sendStartForSlot($slotId);
        };
        $connection->onMessage = fn ($connection, string $data) => $this->handleThirdPartyMessage($slotId, $data);
        $connection->onClose = fn () => $this->handleSlotClose($slotId);
        $connection->onError = fn ($connection, int $code, string $message) => $this->handleSlotError($slotId, $message ?: ('WebSocket error ' . $code));
        $connection->connect();
    }

    private function sendStartForSlot(string $slotId): void
    {
        foreach (array_keys($this->slotAccounts[$slotId] ?? []) as $accountId) {
            if (($this->stopping[$accountId] ?? false) !== true) {
                $this->sendStartForAccount($slotId, (int)$accountId);
            }
        }
    }

    private function sendStartForAccount(string $slotId, int $accountId): void
    {
        $account = $this->accounts->findById($accountId);
        if (!$account) {
            Log::warning('Third-party websocket account not found before start send', ['account_id' => $accountId, 'slot_id' => $slotId]);
            $this->releaseAccount($accountId);
            return;
        }

        $payload = $this->startPayload($account, $this->settings->thirdPartyConfig(), $this->requestIds[$accountId] ?? bin2hex(random_bytes(16)));
        $this->sendJson($this->connections[$slotId], $payload);
        $this->queue->writeAccountState($accountId, ['state' => 'connected', 'slot_id' => $slotId, 'updated_at' => time()]);
    }

    private function handleSlotClose(string $slotId): void
    {
        unset($this->connections[$slotId], $this->connectedSlots[$slotId]);
        if (($this->stoppingSlots[$slotId] ?? false) === true) {
            unset($this->stoppingSlots[$slotId]);
            $this->writeSlotState($slotId, 'disconnected');
            return;
        }

        $accountIds = array_keys($this->slotAccounts[$slotId] ?? []);
        if ($accountIds === []) {
            $this->writeSlotState($slotId, 'disconnected');
            if (($this->desiredSlots[$slotId] ?? false) === true) {
                $attempt = ($this->reconnectAttempts[$slotId] ?? 0) + 1;
                $this->reconnectAttempts[$slotId] = $attempt;
                if ($attempt <= self::MAX_RECONNECT_ATTEMPTS) {
                    Timer::add(min(30, $attempt * 3), fn () => $this->reconnectSlot($slotId), [], false);
                } else {
                    $this->writeSlotState($slotId, 'error', '第三方WebSocket连接断开且重连次数已达上限');
                }
            }
            return;
        }

        $activeAccountIds = [];
        foreach ($accountIds as $accountId) {
            if (($this->stopping[$accountId] ?? false) === true) {
                $this->releaseAccount((int)$accountId);
            } else {
                $activeAccountIds[] = (int)$accountId;
            }
        }
        if ($activeAccountIds === []) {
            return;
        }

        $attempt = ($this->reconnectAttempts[$slotId] ?? 0) + 1;
        $this->reconnectAttempts[$slotId] = $attempt;
        if ($attempt > self::MAX_RECONNECT_ATTEMPTS) {
            foreach ($activeAccountIds as $accountId) {
                $this->markError($accountId, '第三方WebSocket连接断开且重连次数已达上限');
            }
            return;
        }

        foreach ($activeAccountIds as $accountId) {
            $this->appendLog($accountId, '[WARN] 第三方WebSocket连接断开，准备重连');
            $this->queue->writeAccountState($accountId, ['state' => 'disconnected', 'slot_id' => $slotId, 'updated_at' => time()]);
        }
        $this->writeSlotState($slotId, 'disconnected');
        Timer::add(min(30, $attempt * 3), fn () => $this->reconnectSlot($slotId), [], false);
    }

    private function reconnectSlot(string $slotId): void
    {
        unset($this->connections[$slotId], $this->connectedSlots[$slotId]);
        if (($this->slotAccounts[$slotId] ?? []) !== [] || ($this->desiredSlots[$slotId] ?? false) === true) {
            $this->ensureSlotConnection($slotId);
        }
    }

    private function handleSlotError(string $slotId, string $message): void
    {
        $this->writeSlotState($slotId, 'error', $message);
        foreach (array_keys($this->slotAccounts[$slotId] ?? []) as $accountId) {
            $this->appendLog((int)$accountId, '[ERROR] ' . $message);
        }
    }

    private function markStarted(int $accountId, array $payload): void
    {
        $account = $this->accounts->findById($accountId);
        if (!$account) {
            return;
        }

        $updated = $this->accounts->updateRuntimeState((int)$account['user_id'], $accountId, [
            'status' => GameAccountService::RUNNING_STATUS,
            'sync_status' => 'synced',
            'display_name' => (string)($payload['display_name'] ?? ($account['display_name'] ?? '')),
            'third_party_account_id' => (string)($payload['third_party_account_id'] ?? ($account['third_party_account_id'] ?? '')),
        ]);
        $this->bindStartedAccount($accountId, $updated, $payload);
        $this->appendLog($accountId, '[INFO] 第三方启动成功');
    }

    private function bindStartedAccount(int $accountId, array $account, array $payload): void
    {
        if (!$this->startedAccountBinder || !method_exists($this->startedAccountBinder, 'bindStartedAccount')) {
            return;
        }

        try {
            $this->startedAccountBinder->bindStartedAccount($account, $payload);
        } catch (Throwable $e) {
            Log::error('Failed to bind role after third-party started', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            $this->appendLog($accountId, '[ERROR] 启动成功后自动绑定角色失败：' . $e->getMessage());
        }
    }

    private function markStopped(int $accountId, string $message): void
    {
        $account = $this->accounts->findById($accountId);
        if (!$account) {
            $this->releaseAccount($accountId);
            return;
        }

        $this->accounts->updateRuntimeState((int)$account['user_id'], $accountId, [
            'status' => GameAccountService::STOPPED_STATUS,
            'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
            'log_session_id' => '',
        ]);
        $this->accounts->clearLogLines($accountId);
        $this->releaseAccount($accountId);
    }

    private function markError(int $accountId, string $message): void
    {
        $account = $this->accounts->findById($accountId);
        if ($account) {
            $this->accounts->updateRuntimeState((int)$account['user_id'], $accountId, [
                'status' => GameAccountService::ERROR_STATUS,
                'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
            ]);
        }
        $this->appendLog($accountId, '[ERROR] ' . $message);
        $this->releaseAccount($accountId);
    }

    private function releaseAccount(int $accountId): void
    {
        $slotId = $this->accountSlots[$accountId] ?? null;
        if ($slotId) {
            unset($this->slotAccounts[$slotId][$accountId]);
        }
        unset($this->accountSlots[$accountId], $this->requestIds[$accountId], $this->stopping[$accountId]);
        $this->queue->clearAccountState($accountId);
    }

    private function appendLog(int $accountId, string $line): void
    {
        try {
            (new GameAccountLogService($this->accounts, $this->locale))->appendFromThirdParty($accountId, [$line]);
        } catch (Throwable $e) {
            Log::error('Failed to append third-party websocket log', ['account_id' => $accountId, 'error' => $e->getMessage()]);
        }
    }

    private function startPayload(array $account, array $config, string $requestId): array
    {
        return [
            'type' => 'start',
            'request_id' => $requestId,
            'account_id' => (int)$account['id'],
            'game_username' => (string)($account['game_username'] ?? ''),
            'game_password' => (new CredentialCipher((string)($config['credential_key'] ?? ''), $this->locale))
                ->decrypt((string)($account['game_password_cipher'] ?? '')),
            'config' => $this->decodeConfig((string)($account['config_json'] ?? '{}')),
        ];
    }

    private function formatLogPayload(array $payload): string
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

    private function refreshConnectionStates(): void
    {
        foreach ($this->slotUrls as $slotId => $_url) {
            $state = 'disconnected';
            if (($this->connectedSlots[$slotId] ?? false) === true) {
                $state = 'connected';
            } elseif (isset($this->connections[$slotId]) || ($this->desiredSlots[$slotId] ?? false) === true || ($this->slotAccounts[$slotId] ?? []) !== []) {
                $state = 'connecting';
            }
            $this->writeSlotState($slotId, $state);
        }

        foreach ($this->accountSlots as $accountId => $slotId) {
            $state = ($this->connectedSlots[$slotId] ?? false) ? 'connected' : 'connecting';
            $this->queue->writeAccountState((int)$accountId, ['state' => $state, 'slot_id' => $slotId, 'updated_at' => time()]);
        }
    }

    private function wsUrls(array $config): array
    {
        $urls = $config['ws_urls'] ?? [];
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

        $legacyUrl = trim((string)($config['ws_url'] ?? ''));
        return $legacyUrl === '' ? [] : [$legacyUrl];
    }

    private function configuredSlotUrls(?array $config = null): array
    {
        $config ??= $this->settings->thirdPartyConfig();
        $slots = [];
        foreach ($this->wsUrls($config) as $index => $url) {
            $slots['slot-' . ($index + 1)] = $url;
        }
        return $slots;
    }

    private function isValidSlotId(string $slotId): bool
    {
        return (bool)preg_match('/^slot-[1-9]\d*$/', $slotId);
    }

    private function writeSlotState(string $slotId, string $state, string $lastError = ''): void
    {
        $config = $this->settings->thirdPartyConfig();
        $slots = $this->configuredSlotUrls($config);
        $url = $this->slotUrls[$slotId] ?? ($slots[$slotId] ?? '');
        $accountIds = array_map('intval', array_keys($this->slotAccounts[$slotId] ?? []));
        sort($accountIds);
        $this->queue->writeSlotState($slotId, [
            'slot_id' => $slotId,
            'url' => $url,
            'state' => $state,
            'account_ids' => $accountIds,
            'account_count' => count($accountIds),
            'capacity' => max(1, (int)($config['ws_connection_capacity'] ?? self::DEFAULT_CONNECTION_CAPACITY)),
            'last_error' => $lastError,
            'updated_at' => time(),
        ]);
    }

    private function sendJson(object $connection, array $payload): void
    {
        $connection->send(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function decodeConfig(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $config = json_decode($json, true);
        if (!is_array($config)) {
            throw new RuntimeException('游戏配置JSON格式错误，不能发送空配置给第三方');
        }
        return $config;
    }
}
