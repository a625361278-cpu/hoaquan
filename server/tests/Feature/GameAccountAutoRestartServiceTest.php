<?php

namespace tests\Feature;

use app\service\CredentialCipher;
use app\service\GameAccountAutoRestartService;
use app\service\GameAccountService;
use app\service\GameAccountTaskStateService;
use app\service\GameLogMessage;
use PHPUnit\Framework\TestCase;
use tests\Support\ArrayGameAccountRepository;
use tests\Support\ArrayGameAccountTaskStatePendingStore;
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

        $service->scheduleReconnect(3, 'client.logs.system.runtime_connection_closed_reconnecting', 'session-1');

        $account = $repository->findById(3);
        $this->assertSame(GameAccountService::RECONNECTING_STATUS, $account['status']);
        $this->assertSame(1, (int)$account['desired_running']);
        $this->assertSame(0, (int)$account['auto_restart_attempts']);
        $this->assertSame(1, $repository->countNormalLogLines(3, 'session-1'));
        $this->assertSame('session-1', $queue->normal[0]['session_id']);
        $this->assertStringContainsString(GameLogMessage::PREFIX, $queue->normal[0]['lines'][0]);
        $this->assertStringContainsString('client.logs.system.runtime_connection_closed_reconnecting', $queue->normal[0]['lines'][0]);
        $this->assertSame([], $queue->events);
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
        $repository->saveTaskState(
            3,
            '{"step":5}',
            hash('sha256', '{"step":5}'),
            strlen('{"step":5}'),
            '2026-07-09 12:00:00'
        );
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
        $this->assertTrue($runtime->started[0]['task_state']['exists']);
        $this->assertSame(5, $runtime->started[0]['task_state']['state']->step);
    }

    public function testReconnectUsesEncryptedSocialTokenInsteadOfPassword(): void
    {
        $repository = $this->repository([
            'login_method' => 2,
            'game_username' => '',
            'game_password_cipher' => null,
            'game_uid' => 'facebook-uid-1001',
            'game_token_cipher' => (new CredentialCipher('test-key'))->encrypt('facebook-token-secret'),
            'status' => GameAccountService::RECONNECTING_STATUS,
            'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
            'log_session_id' => 'session-social',
            'desired_running' => 1,
            'auto_restart_next_at' => '2026-07-07 10:00:00',
        ]);
        $runtime = new ArrayThirdPartyScriptRuntime(true);
        $service = $this->service($repository, runtime: $runtime);

        $result = $service->runDue(10);

        $this->assertSame(1, $result['started']);
        $this->assertSame(2, $runtime->started[0]['login_method']);
        $this->assertSame('facebook-token-secret', $runtime->started[0]['credential']);
        $this->assertSame('session-social', $runtime->started[0]['session_id']);
    }

    public function testReconnectSkipsExpiredAccountWithoutSendingStart(): void
    {
        $repository = $this->repository([
            'status' => GameAccountService::RECONNECTING_STATUS,
            'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
            'log_session_id' => 'session-1',
            'desired_running' => 1,
            'auto_restart_attempts' => 2,
            'auto_restart_next_at' => '2026-07-07 10:00:00',
            'expire_time' => '2026-07-07 09:59:59',
        ]);
        $runtime = new ArrayThirdPartyScriptRuntime(true);
        $service = $this->service($repository, runtime: $runtime);

        $result = $service->runDue(10);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame([], $runtime->started);
        $this->assertSame(GameAccountService::RECONNECTING_STATUS, $repository->findById(3)['status']);
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
        $this->assertStringContainsString('client.logs.system.runtime_connection_missing_reconnecting', $queue->normal[0]['lines'][0]);
        $this->assertSame([], $queue->events);
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
        $queue = new ArrayGameLogQueue();
        $service = $this->service($repository, runtime: $runtime, queue: $queue);

        $result = $service->runDue(10);

        $account = $repository->findById(3);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(GameAccountService::ERROR_STATUS, $account['status']);
        $this->assertSame(0, (int)$account['desired_running']);
        $this->assertSame(GameAccountAutoRestartService::MAX_ATTEMPTS, (int)$account['auto_restart_attempts']);
        $this->assertStringContainsString('client.logs.system.auto_reconnect_stopped', $queue->normal[0]['lines'][0]);
        $this->assertSame([], $queue->events);
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
            static fn (): int => 1783428000,
            new GameAccountTaskStateService($repository, 1024, pendingStore: new ArrayGameAccountTaskStatePendingStore())
        );
    }

    private function repository(array $overrides): ArrayGameAccountRepository
    {
        return new ArrayGameAccountRepository([
            array_merge([
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'login_method' => 1,
                'game_username' => 'any-player',
                'game_password_cipher' => (new CredentialCipher('test-key'))->encrypt('secret-password'),
                'game_uid' => '',
                'game_token_cipher' => null,
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
