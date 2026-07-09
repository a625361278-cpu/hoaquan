<?php

namespace tests\Feature;

use app\service\GameAccountExpiryService;
use app\service\GameAccountService;
use PHPUnit\Framework\TestCase;
use tests\Support\ArrayGameAccountRepository;
use tests\Support\ArrayGameLogQueue;
use tests\Support\ArrayThirdPartyScriptRuntime;

class GameAccountExpiryServiceTest extends TestCase
{
    public function testExpiredRunningAccountSendsStopAndMarksStopping(): void
    {
        $repository = $this->repository([
            'status' => GameAccountService::RUNNING_STATUS,
            'desired_running' => 1,
            'log_session_id' => 'session-1',
            'expire_time' => '2026-07-08 11:59:59',
        ]);
        $runtime = new ArrayThirdPartyScriptRuntime();
        $logs = new ArrayGameLogQueue();
        $service = new GameAccountExpiryService(
            $repository,
            $runtime,
            $logs,
            static fn (): int => strtotime('2026-07-08 12:00:00')
        );

        $result = $service->stopExpiredActiveAccounts(10);

        $account = $repository->findById(3);
        $this->assertSame(1, $result['stopping']);
        $this->assertSame(GameAccountService::STOPPING_STATUS, $account['status']);
        $this->assertSame(0, (int)$account['desired_running']);
        $this->assertSame(3, $runtime->stopped[0]['account_id']);
        $this->assertStringContainsString('配额到期', $logs->normal[0]['lines'][0]);
        $this->assertSame([], $logs->events);
    }

    public function testExpiredAccountWithoutRuntimeBindingIsMarkedStopped(): void
    {
        $repository = $this->repository([
            'status' => GameAccountService::RECONNECTING_STATUS,
            'desired_running' => 1,
            'log_session_id' => 'session-1',
            'expire_time' => '2026-07-08 11:59:59',
        ]);
        $runtime = new ArrayThirdPartyScriptRuntime();
        $runtime->stopSent = false;
        $service = new GameAccountExpiryService(
            $repository,
            $runtime,
            new ArrayGameLogQueue(),
            static fn (): int => strtotime('2026-07-08 12:00:00')
        );

        $result = $service->stopExpiredActiveAccounts(10);

        $account = $repository->findById(3);
        $this->assertSame(1, $result['stopped']);
        $this->assertSame(GameAccountService::STOPPED_STATUS, $account['status']);
        $this->assertSame(0, (int)$account['desired_running']);
    }

    public function testUnexpiredActiveAccountIsNotStopped(): void
    {
        $repository = $this->repository([
            'status' => GameAccountService::RUNNING_STATUS,
            'desired_running' => 1,
            'log_session_id' => 'session-1',
            'expire_time' => '2026-07-08 12:00:01',
        ]);
        $runtime = new ArrayThirdPartyScriptRuntime();
        $service = new GameAccountExpiryService(
            $repository,
            $runtime,
            new ArrayGameLogQueue(),
            static fn (): int => strtotime('2026-07-08 12:00:00')
        );

        $result = $service->stopExpiredActiveAccounts(10);

        $this->assertSame(0, $result['checked']);
        $this->assertSame([], $runtime->stopped);
        $this->assertSame(GameAccountService::RUNNING_STATUS, $repository->findById(3)['status']);
    }

    private function repository(array $overrides): ArrayGameAccountRepository
    {
        return new ArrayGameAccountRepository([
            array_merge([
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'game_username' => 'any-player',
                'game_password_cipher' => '',
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
