<?php

namespace tests\Feature;

use app\service\CredentialCipher;
use app\service\GameAccountAutoRestartService;
use app\service\GameAccountService;
use PHPUnit\Framework\TestCase;
use tests\Support\ArrayGameAccountRepository;
use tests\Support\ArrayGameLogQueue;
use tests\Support\ArrayThirdPartyScriptConnectionStore;
use tests\Support\ArrayThirdPartyScriptRuntime;

class GameAccountAutoRestartServiceTest extends TestCase
{
    public function testConnectionLossSchedulesReconnectWithoutClearingCurrentSessionLogs(): void
    {
        $repository = $this->repository([
            'status' => GameAccountService::RUNNING_STATUS,
            'sync_status' => 'synced',
            'log_session_id' => 'session-1',
            'desired_running' => 1,
        ]);
        $repository->appendNormalLogLines(3, 'session-1', ['line before disconnect'], 2500);
        $queue = new ArrayGameLogQueue();
        $service = $this->service($repository, queue: $queue);

        $service->scheduleReconnect(3, '第三方脚本连接断开', 'session-1');

        $account = $repository->findById(3);
        $this->assertSame(GameAccountService::RECONNECTING_STATUS, $account['status']);
        $this->assertSame(1, (int)$account['desired_running']);
        $this->assertSame(0, (int)$account['auto_restart_attempts']);
        $this->assertSame(1, $repository->countNormalLogLines(3, 'session-1'));
        $this->assertSame('session-1', $queue->normal[0]['session_id']);
        $this->assertStringContainsString('等待自动重连', $queue->normal[0]['lines'][0]);
    }

    public function testReconnectWaitsForIdleConnectionWithoutIncreasingAttempts(): void
    {
        $repository = $this->repository([
            'status' => GameAccountService::RECONNECTING_STATUS,
            'log_session_id' => 'session-1',
            'desired_running' => 1,
            'auto_restart_attempts' => 3,
            'auto_restart_next_at' => '2026-07-07 10:00:00',
        ]);
        $runtime = new ArrayThirdPartyScriptRuntime(false);
        $service = $this->service($repository, runtime: $runtime);

        $result = $service->runDue(10);

        $account = $repository->findById(3);
        $this->assertSame(1, $result['waiting']);
        $this->assertSame(GameAccountService::RECONNECTING_STATUS, $account['status']);
        $this->assertSame(3, (int)$account['auto_restart_attempts']);
        $this->assertSame([], $runtime->started);
    }

    public function testReconnectUsesExistingLogSessionAndSendsIdempotentStart(): void
    {
        $repository = $this->repository([
            'status' => GameAccountService::RECONNECTING_STATUS,
            'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
            'log_session_id' => 'session-1',
            'desired_running' => 1,
            'auto_restart_attempts' => 2,
            'auto_restart_next_at' => '2026-07-07 10:00:00',
            'config_json' => '{"basic":{"debug":true}}',
        ]);
        $runtime = new ArrayThirdPartyScriptRuntime(true);
        $service = $this->service($repository, runtime: $runtime);

        $result = $service->runDue(10);

        $account = $repository->findById(3);
        $this->assertSame(1, $result['started']);
        $this->assertSame(GameAccountService::STARTING_STATUS, $account['status']);
        $this->assertSame(0, (int)$account['auto_restart_attempts']);
        $this->assertSame('session-1', $runtime->started[0]['session_id']);
        $this->assertSame('secret-password', $runtime->started[0]['game_password']);
        $this->assertSame(['basic' => ['debug' => true]], $runtime->started[0]['config']);
    }

    public function testReconcileSchedulesDesiredRunningAccountWithoutConnectionBinding(): void
    {
        $repository = $this->repository([
            'status' => GameAccountService::RUNNING_STATUS,
            'sync_status' => 'synced',
            'log_session_id' => 'session-1',
            'desired_running' => 1,
        ]);
        $queue = new ArrayGameLogQueue();
        $service = $this->service($repository, queue: $queue);

        $result = $service->reconcileMissingBindings(0, 10);

        $account = $repository->findById(3);
        $this->assertSame(1, $result['scheduled']);
        $this->assertSame(GameAccountService::RECONNECTING_STATUS, $account['status']);
        $this->assertStringContainsString('连接丢失', $queue->normal[0]['lines'][0]);
    }

    public function testReconnectStopsRetryingAfterMaximumSendFailures(): void
    {
        $repository = $this->repository([
            'status' => GameAccountService::RECONNECTING_STATUS,
            'log_session_id' => 'session-1',
            'desired_running' => 1,
            'auto_restart_attempts' => GameAccountAutoRestartService::MAX_ATTEMPTS - 1,
            'auto_restart_next_at' => '2026-07-07 10:00:00',
        ]);
        $runtime = new ArrayThirdPartyScriptRuntime(true);
        $runtime->failSend = true;
        $service = $this->service($repository, runtime: $runtime);

        $result = $service->runDue(10);

        $account = $repository->findById(3);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(GameAccountService::ERROR_STATUS, $account['status']);
        $this->assertSame(0, (int)$account['desired_running']);
        $this->assertSame(GameAccountAutoRestartService::MAX_ATTEMPTS, (int)$account['auto_restart_attempts']);
    }

    private function service(
        ArrayGameAccountRepository $repository,
        ?ArrayThirdPartyScriptRuntime $runtime = null,
        ?ArrayThirdPartyScriptConnectionStore $connections = null,
        ?ArrayGameLogQueue $queue = null
    ): GameAccountAutoRestartService {
        return new GameAccountAutoRestartService(
            $repository,
            $runtime ?? new ArrayThirdPartyScriptRuntime(true),
            $connections ?? new ArrayThirdPartyScriptConnectionStore(),
            $queue ?? new ArrayGameLogQueue(),
            'test-key',
            static fn (): int => 1783428000
        );
    }

    private function repository(array $overrides): ArrayGameAccountRepository
    {
        return new ArrayGameAccountRepository([
            array_merge([
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'game_username' => 'any-player',
                'game_password_cipher' => (new CredentialCipher('test-key'))->encrypt('secret-password'),
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => GameAccountService::STOPPED_STATUS,
                'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
                'third_party_account_id' => '',
                'log_session_id' => '',
                'desired_running' => 0,
                'auto_restart_attempts' => 0,
                'auto_restart_next_at' => null,
                'auto_restart_last_error' => '',
                'remark' => '',
                'config_json' => '{}',
            ], $overrides),
        ]);
    }
}
