<?php

namespace tests\Feature;

use app\process\ThirdPartyConnectionWorker;
use app\service\CredentialCipher;
use app\service\GameAccountService;
use app\service\SystemSettingService;
use PHPUnit\Framework\TestCase;
use tests\Support\ArrayGameAccountRepository;
use tests\Support\ArrayThirdPartyCommandQueue;

class ThirdPartyConnectionWorkerTest extends TestCase
{
    public function testStartedMessageMarksAccountRunning(): void
    {
        [$worker] = $this->workerWithStartedAccounts([$this->account(3, 'starting')]);

        $worker->handleThirdPartyMessage('slot-1', json_encode([
            'type' => 'started',
            'account_id' => 3,
            'display_name' => 'role-name',
        ], JSON_UNESCAPED_UNICODE));

        $account = $this->repository->findById(3);
        $this->assertSame(GameAccountService::RUNNING_STATUS, $account['status']);
        $this->assertSame('synced', $account['sync_status']);
        $this->assertSame('', $account['server_id']);
        $this->assertSame('', $account['third_party_account_id']);
        $this->assertSame('role-name', $account['display_name']);
    }

    public function testStartedMessageTriggersAutomaticRoleBinding(): void
    {
        $binder = new class {
            public array $calls = [];

            public function bindStartedAccount(array $account, array $payload): void
            {
                $this->calls[] = [
                    'account_id' => (int)$account['id'],
                    'user_id' => (int)$account['user_id'],
                    'role_id' => $payload['third_party_account_id'] ?? '',
                ];
            }
        };
        [$worker] = $this->workerWithStartedAccounts([$this->account(3, 'starting')], binder: $binder);

        $worker->handleThirdPartyMessage('slot-1', json_encode([
            'type' => 'started',
            'account_id' => 3,
            'third_party_account_id' => 'role-001',
            'display_name' => 'role-name',
        ], JSON_UNESCAPED_UNICODE));

        $this->assertSame([
            [
                'account_id' => 3,
                'user_id' => 7,
                'role_id' => 'role-001',
            ],
        ], $binder->calls);
    }

    public function testLogMessageIsStoredByPayloadAccountId(): void
    {
        [$worker] = $this->workerWithStartedAccounts([$this->account(3, 'running')]);

        $worker->handleThirdPartyMessage('slot-1', json_encode([
            'type' => 'log',
            'account_id' => 3,
            'level' => 'info',
            'category' => 'Loader',
            'message' => '登录成功',
            'time' => '2026-07-03 12:00:00',
        ], JSON_UNESCAPED_UNICODE));

        $logs = $this->repository->listLogLines(3, 0, 10);
        $this->assertStringContainsString('[INFO]', $logs[0]['message']);
        $this->assertStringContainsString('[Loader]', $logs[0]['message']);
        $this->assertStringContainsString('登录成功', $logs[0]['message']);
    }

    public function testWorkerLogStorageKeepsLatest2500Lines(): void
    {
        [$worker] = $this->workerWithStartedAccounts([$this->account(3, 'running')]);

        for ($i = 1; $i <= 2502; $i++) {
            $worker->handleThirdPartyMessage('slot-1', json_encode([
                'type' => 'log',
                'account_id' => 3,
                'level' => 'info',
                'category' => 'Loader',
                'message' => 'line ' . $i,
                'time' => '2026-07-03 12:00:00',
            ], JSON_UNESCAPED_UNICODE));
        }

        $logs = $this->repository->listLogLines(3, 0, 2500);
        $this->assertCount(2500, $logs);
        $this->assertStringContainsString('line 3', $logs[0]['message']);
        $this->assertStringContainsString('line 2502', $logs[2499]['message']);
    }

    public function testStoppedMessageMarksStoppedAndClearsAccountState(): void
    {
        [$worker, $queue] = $this->workerWithStartedAccounts([$this->account(3, 'running')]);
        $this->repository->appendLogLines(3, ['line 1'], 2500);

        $worker->handleThirdPartyMessage('slot-1', json_encode([
            'type' => 'stopped',
            'account_id' => 3,
            'message' => '已停止',
        ], JSON_UNESCAPED_UNICODE));

        $account = $this->repository->findById(3);
        $this->assertSame(GameAccountService::STOPPED_STATUS, $account['status']);
        $this->assertSame('', $account['log_session_id']);
        $this->assertSame([], $queue->states);
        $this->assertSame(0, $this->repository->countLogLines(3));
    }

    public function testErrorMessageMarksAccountError(): void
    {
        [$worker] = $this->workerWithStartedAccounts([$this->account(3, 'starting')]);

        $worker->handleThirdPartyMessage('slot-1', json_encode([
            'type' => 'error',
            'account_id' => 3,
            'message' => '登录失败',
        ], JSON_UNESCAPED_UNICODE));

        $this->assertSame(GameAccountService::ERROR_STATUS, $this->repository->findById(3)['status']);
        $this->assertStringContainsString('登录失败', $this->repository->listLogLines(3, 0, 10)[0]['message']);
    }

    public function testRecoverRunningAccountsEnqueuesStartCommands(): void
    {
        $queue = new ArrayThirdPartyCommandQueue();
        $worker = new ThirdPartyConnectionWorker(new ArrayGameAccountRepository([
            $this->account(3, 'starting'),
            $this->account(4, 'running'),
            $this->account(5, 'stopped'),
        ]), $queue, $this->settings());

        $worker->recoverRunningAccounts();

        $this->assertSame([3, 4], array_column($queue->commands, 'account_id'));
        $this->assertSame(['start', 'start'], array_column($queue->commands, 'action'));
    }

    public function testStartPayloadSendsAccountIdLoginCredentialsAndConfigOnly(): void
    {
        $account = $this->account(3, 'starting');
        $account['config_json'] = json_encode(['basic' => ['debug' => true]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $worker = new ThirdPartyConnectionWorker(new ArrayGameAccountRepository([$account]), new ArrayThirdPartyCommandQueue(), $this->settings());

        $method = new \ReflectionMethod($worker, 'startPayload');
        $method->setAccessible(true);
        $payload = $method->invoke($worker, $account, ['credential_key' => 'test-key'], 'request-1');

        $this->assertSame([
            'type' => 'start',
            'request_id' => 'request-1',
            'account_id' => 3,
            'game_username' => 'any-player',
            'game_password' => 'secret-password',
            'config' => ['basic' => ['debug' => true]],
        ], $payload);
        $this->assertArrayNotHasKey('account', $payload);
        $this->assertArrayNotHasKey('server_id', $payload);
        $this->assertArrayNotHasKey('server_name', $payload);
    }

    public function testPoolReusesConnectionUntilCapacityThenUsesNextSlot(): void
    {
        [$worker, , $connections] = $this->workerWithStartedAccounts([
            $this->account(3, 'starting'),
            $this->account(4, 'starting'),
            $this->account(5, 'starting'),
        ], capacity: 2, urls: ['ws://third-party/a', 'ws://third-party/b']);

        $this->assertArrayHasKey('slot-1', $connections);
        $this->assertArrayHasKey('slot-2', $connections);
        $this->assertCount(2, $connections['slot-1']->sent);
        $this->assertCount(1, $connections['slot-2']->sent);
        $this->assertSame(3, json_decode($connections['slot-1']->sent[0], true)['account_id']);
        $this->assertSame(4, json_decode($connections['slot-1']->sent[1], true)['account_id']);
        $this->assertSame(5, json_decode($connections['slot-2']->sent[0], true)['account_id']);

        $worker->handleThirdPartyMessage('slot-2', json_encode([
            'type' => 'status',
            'account_id' => 5,
            'resources' => ['level' => 14],
        ], JSON_UNESCAPED_UNICODE));

        $this->assertStringContainsString('"level":14', $this->repository->listLogLines(5, 0, 10)[0]['message']);
    }

    public function testCapacityFullMarksOnlyNewAccountError(): void
    {
        [$worker] = $this->workerWithStartedAccounts([
            $this->account(3, 'starting'),
            $this->account(4, 'starting'),
        ], capacity: 1, urls: ['ws://third-party/a'], startAccountIds: [3]);

        $worker->handleCommand([
            'command_id' => 'request-4',
            'account_id' => 4,
            'action' => 'start',
        ]);

        $this->assertSame(GameAccountService::STARTING_STATUS, $this->repository->findById(3)['status']);
        $this->assertSame(GameAccountService::ERROR_STATUS, $this->repository->findById(4)['status']);
        $this->assertStringContainsString('连接池容量已满', $this->repository->listLogLines(4, 0, 10)[0]['message']);
    }

    public function testStopSendsAccountStopWithoutClosingSharedConnection(): void
    {
        [$worker, , $connections] = $this->workerWithStartedAccounts([
            $this->account(3, 'running'),
            $this->account(4, 'running'),
        ], capacity: 10);

        $worker->handleCommand([
            'command_id' => 'stop-3',
            'account_id' => 3,
            'action' => 'stop',
        ]);

        $this->assertFalse($connections['slot-1']->closed);
        $sent = $connections['slot-1']->sent;
        $lastPayload = json_decode(end($sent), true);
        $this->assertSame('stop', $lastPayload['type']);
        $this->assertSame(3, $lastPayload['account_id']);
    }

    public function testStartSlotCreatesEmptyConnectionAndWritesSlotState(): void
    {
        [, $queue, $connections] = $this->workerWithStartedAccounts([], urls: ['ws://third-party/a'], startAccountIds: [], initialCommands: [[
            'command_id' => 'start-slot-1',
            'slot_id' => 'slot-1',
            'action' => 'start_slot',
        ]]);

        $this->assertArrayHasKey('slot-1', $connections);
        $this->assertSame('ws://third-party/a', $connections['slot-1']->url);
        $this->assertSame([], $connections['slot-1']->sent);
        $this->assertSame('connected', $queue->slotStates['slot-1']['state']);
        $this->assertSame(0, $queue->slotStates['slot-1']['account_count']);
        $this->assertSame(10, $queue->slotStates['slot-1']['capacity']);
    }

    public function testStartAllSlotsConnectsEveryConfiguredUrl(): void
    {
        [, $queue, $connections] = $this->workerWithStartedAccounts([], urls: [
            'ws://third-party/a',
            'ws://third-party/b',
        ], startAccountIds: [], initialCommands: [[
            'command_id' => 'start-all',
            'action' => 'start_all_slots',
        ]]);

        $this->assertSame(['slot-1', 'slot-2'], array_keys($connections));
        $this->assertSame('connected', $queue->slotStates['slot-1']['state']);
        $this->assertSame('connected', $queue->slotStates['slot-2']['state']);
    }

    public function testAccountStartReusesPrestartedSlot(): void
    {
        [, $queue, $connections] = $this->workerWithStartedAccounts([
            $this->account(3, 'starting'),
        ], urls: ['ws://third-party/a'], startAccountIds: [], initialCommands: [[
            'command_id' => 'start-slot-1',
            'slot_id' => 'slot-1',
            'action' => 'start_slot',
        ], [
            'command_id' => 'request-3',
            'account_id' => 3,
            'action' => 'start',
        ]]);

        $this->assertCount(1, $connections);
        $this->assertCount(1, $connections['slot-1']->sent);
        $payload = json_decode($connections['slot-1']->sent[0], true);
        $this->assertSame('start', $payload['type']);
        $this->assertSame(3, $payload['account_id']);
        $this->assertSame('connected', $queue->slotStates['slot-1']['state']);
        $this->assertSame([3], $queue->slotStates['slot-1']['account_ids']);
    }

    public function testStopSlotForcesAssignedAccountsToStopAndClosesConnection(): void
    {
        [$worker, $queue, $connections] = $this->workerWithStartedAccounts([
            $this->account(3, 'running'),
            $this->account(4, 'running'),
        ]);

        $worker->handleCommand([
            'command_id' => 'stop-slot-1',
            'slot_id' => 'slot-1',
            'action' => 'stop_slot',
            'force' => true,
        ]);

        $this->assertTrue($connections['slot-1']->closed);
        $this->assertSame(GameAccountService::STOPPED_STATUS, $this->repository->findById(3)['status']);
        $this->assertSame(GameAccountService::STOPPED_STATUS, $this->repository->findById(4)['status']);
        $this->assertSame('disconnected', $queue->slotStates['slot-1']['state']);
        $this->assertSame([], $queue->slotStates['slot-1']['account_ids']);

        $stopPayloads = array_values(array_filter(array_map(
            static fn (string $payload): array => json_decode($payload, true),
            $connections['slot-1']->sent
        ), static fn (array $payload): bool => ($payload['type'] ?? '') === 'stop'));
        $this->assertSame([3, 4], array_column($stopPayloads, 'account_id'));
    }

    public function testStartSlotWithoutConfiguredUrlWritesErrorState(): void
    {
        [, $queue, $connections] = $this->workerWithStartedAccounts([], urls: [], startAccountIds: [], initialCommands: [[
            'command_id' => 'start-slot-1',
            'slot_id' => 'slot-1',
            'action' => 'start_slot',
        ]]);

        $this->assertSame([], $connections);
        $this->assertSame('error', $queue->slotStates['slot-1']['state']);
        $this->assertStringContainsString('未配置', $queue->slotStates['slot-1']['last_error']);
    }

    public function testMessageWithoutAccountIdDoesNotUpdateAnyAccount(): void
    {
        [$worker] = $this->workerWithStartedAccounts([$this->account(3, 'starting')]);

        $worker->handleThirdPartyMessage('slot-1', json_encode([
            'type' => 'started',
            'display_name' => 'wrong',
        ], JSON_UNESCAPED_UNICODE));

        $this->assertSame(GameAccountService::STARTING_STATUS, $this->repository->findById(3)['status']);
        $this->assertSame(0, $this->repository->countLogLines(3));
    }

    public function testMessageForAccountAssignedToAnotherSlotIsRejected(): void
    {
        [$worker] = $this->workerWithStartedAccounts([
            $this->account(3, 'running'),
            $this->account(4, 'running'),
        ], capacity: 1, urls: ['ws://third-party/a', 'ws://third-party/b']);

        $worker->handleThirdPartyMessage('slot-2', json_encode([
            'type' => 'log',
            'account_id' => 3,
            'message' => 'should not be stored',
        ], JSON_UNESCAPED_UNICODE));

        $this->assertSame(0, $this->repository->countLogLines(3));
    }

    private ArrayGameAccountRepository $repository;

    private function workerWithStartedAccounts(
        array $accounts,
        int $capacity = 10,
        array $urls = ['ws://third-party/a', 'ws://third-party/b'],
        ?object $binder = null,
        ?array $startAccountIds = null,
        array $initialCommands = []
    ): array
    {
        $this->repository = new ArrayGameAccountRepository($accounts);
        $queue = new ArrayThirdPartyCommandQueue();
        $connections = [];
        $factory = static function (string $url, string $slotId) use (&$connections): FakeThirdPartyConnection {
            $connection = new FakeThirdPartyConnection($url, $slotId);
            $connections[$slotId] = $connection;
            return $connection;
        };
        $worker = new ThirdPartyConnectionWorker(
            $this->repository,
            $queue,
            $this->settings($urls, $capacity),
            \app\support\I18n::DEFAULT_LOCALE,
            $binder,
            $factory
        );

        $startAccountIds ??= array_map(static fn (array $account): int => (int)$account['id'], $accounts);
        foreach ($accounts as $account) {
            if (!in_array((int)$account['id'], $startAccountIds, true)) {
                continue;
            }
            $worker->handleCommand([
                'command_id' => 'request-' . $account['id'],
                'account_id' => (int)$account['id'],
                'action' => 'start',
            ]);
        }
        foreach ($initialCommands as $command) {
            $worker->handleCommand($command);
        }

        return [$worker, $queue, $connections];
    }

    private function settings(array $urls = ['ws://third-party/a'], int $capacity = 10): SystemSettingService
    {
        return new class($urls, $capacity) extends SystemSettingService {
            public function __construct(private array $urls, private int $capacity)
            {
            }

            public function thirdPartyConfig(): array
            {
                return [
                    'enabled' => true,
                    'ws_url' => $this->urls[0] ?? '',
                    'ws_urls' => $this->urls,
                    'ws_connection_capacity' => $this->capacity,
                    'credential_key' => 'test-key',
                ];
            }
        };
    }

    private function account(int $id, string $status): array
    {
        return [
            'id' => $id,
            'user_id' => 7,
            'display_name' => 'any-player',
            'game_username' => 'any-player',
            'game_password_cipher' => (new CredentialCipher('test-key'))->encrypt('secret-password'),
            'channel_code' => 'official_app',
            'server_id' => '',
            'server_name' => '',
            'status' => $status,
            'sync_status' => 'local_unsynced',
            'third_party_account_id' => '',
            'log_session_id' => 'session-1',
            'remark' => '',
            'config_json' => '{}',
        ];
    }
}

class FakeThirdPartyConnection
{
    public $onConnect = null;
    public $onMessage = null;
    public $onClose = null;
    public $onError = null;
    public array $sent = [];
    public bool $closed = false;

    public function __construct(public string $url, public string $slotId)
    {
    }

    public function connect(): void
    {
        if ($this->onConnect) {
            ($this->onConnect)($this);
        }
    }

    public function send(string $data): void
    {
        $this->sent[] = $data;
    }

    public function close(): void
    {
        $this->closed = true;
        if ($this->onClose) {
            ($this->onClose)();
        }
    }
}
