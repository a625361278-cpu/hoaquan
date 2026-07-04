<?php

namespace tests\Feature;

use app\service\GameAccountService;
use PHPUnit\Framework\TestCase;
use tests\Support\ArrayGameAccountRepository;
use tests\Support\ArrayThirdPartyCommandQueue;

class GameAccountServiceTest extends TestCase
{
    public function testEmptyGameAccountListIsReturnedAsRealEmptyState(): void
    {
        $service = new GameAccountService(new ArrayGameAccountRepository([]));

        $result = $service->listForUser(1);

        $this->assertSame(0, $result['code']);
        $this->assertSame([], $result['data']['items']);
        $this->assertSame('未添加游戏账号', $result['data']['empty_text']);
    }

    public function testCreateGameAccountCreatesLocalPreviewWhenThirdPartyApiIsDisabled(): void
    {
        $service = new GameAccountService(new ArrayGameAccountRepository([]), [
            'enabled' => false,
            'credential_key' => 'test-key',
        ]);

        $result = $service->createFromLogin(7, [
            'channel_code' => 'official_app',
            'game_username' => 'any-player',
            'game_password' => 'anything',
        ]);

        $this->assertSame(0, $result['code']);
        $this->assertSame('local_preview', $result['data']['account']['status']);
        $this->assertSame('local_unsynced', $result['data']['account']['sync_status']);
        $this->assertSame('any-player', $result['data']['account']['display_name']);
        $this->assertSame('本地预览帐号已添加，尚未同步第三方接口', $result['msg']);
    }

    public function testCreateGameAccountRequiresCredentialKey(): void
    {
        $service = new GameAccountService(new ArrayGameAccountRepository([]), [
            'enabled' => false,
            'credential_key' => '',
        ]);

        $this->expectException(\app\exception\ApiException::class);
        $this->expectExceptionMessage('游戏账号密码加密密钥未配置');

        $service->createFromLogin(7, [
            'channel_code' => 'official_app',
            'game_username' => 'any-player',
            'game_password' => 'anything',
        ]);
    }

    public function testSaveConfigStoresLocalUnsyncedConfig(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player - 本地预览区服',
                'game_username' => 'any-player',
                'channel_code' => 'official_app',
                'server_id' => 'preview',
                'server_name' => '本地预览区服',
                'status' => 'local_preview',
                'sync_status' => 'local_unsynced',
                'remark' => '第三方接口未配置，本地预览帐号',
                'config_json' => '{}',
            ],
        ]);
        $service = new GameAccountService($repository, ['enabled' => false]);

        $config = [
            'basic' => [
                'debug' => true,
                'reputation' => [
                    'enabled' => true,
                    'threshold' => 80,
                ],
                'task' => [
                    'daily' => false,
                ],
                'reconnectInterval' => 5,
            ],
        ];

        $result = $service->saveConfig(7, 3, $config);

        $this->assertSame(0, $result['code']);
        $this->assertSame('local_unsynced', $result['data']['sync_status']);
        $this->assertSame($config, $result['data']['config']);
        $this->assertSame('本地配置已保存，尚未同步第三方接口', $result['msg']);
    }

    public function testStartFailsClearlyWhenThirdPartyApiIsDisabled(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'game_username' => 'any-player',
                'game_password_cipher' => (new \app\service\CredentialCipher('test-key'))->encrypt('anything'),
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'local_unsynced',
                'remark' => '第三方接口未配置，本地预览帐号',
                'config_json' => '{}',
            ],
        ]);
        $service = new GameAccountService($repository, ['enabled' => false, 'credential_key' => 'test-key']);

        $this->expectException(\app\exception\ApiException::class);
        $this->expectExceptionMessage('第三方接口未启用，不能同步配置');

        $service->start(7, 3);
    }

    public function testStartQueuesWebSocketCommandAndMarksAccountStarting(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'game_username' => 'any-player',
                'game_password_cipher' => (new \app\service\CredentialCipher('test-key'))->encrypt('anything'),
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'local_unsynced',
                'third_party_account_id' => '',
                'remark' => '第三方接口未配置，本地预览帐号',
                'config_json' => '{}',
            ],
        ]);
        $queue = new ArrayThirdPartyCommandQueue();
        $service = new GameAccountService($repository, [
            'enabled' => true,
            'transport' => 'websocket',
            'ws_url' => 'ws://127.0.0.1:9999',
            'credential_key' => 'test-key',
        ], \app\support\I18n::DEFAULT_LOCALE, $queue);

        $result = $service->start(7, 3);

        $this->assertSame(0, $result['code']);
        $this->assertSame('starting', $result['data']['account']['status']);
        $this->assertSame('start', $queue->commands[0]['action']);
        $this->assertSame(3, $queue->commands[0]['account_id']);
        $this->assertSame('启动任务已提交，等待第三方登录确认', $result['msg']);
    }

    public function testStopQueuesCommandMarksStoppedAndClearsLogs(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'game_username' => 'any-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'running',
                'sync_status' => 'synced',
                'third_party_account_id' => '',
                'log_session_id' => 'session-1',
                'remark' => '',
                'config_json' => '{}',
            ],
        ]);
        $repository->appendLogLines(3, ['line 1'], 2500);
        $queue = new ArrayThirdPartyCommandQueue();
        $service = new GameAccountService($repository, ['enabled' => true], \app\support\I18n::DEFAULT_LOCALE, $queue);

        $result = $service->stop(7, 3);

        $this->assertSame(0, $result['code']);
        $this->assertSame('stopped', $result['data']['account']['status']);
        $this->assertSame('local_unsynced', $result['data']['account']['sync_status']);
        $this->assertSame('', $result['data']['account']['log_session_id']);
        $this->assertSame('stop', $queue->commands[0]['action']);
        $this->assertSame(0, $repository->countLogLines(3));
    }

    public function testLogStorageKeepsLatest2500Lines(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'game_username' => 'any-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'running',
                'sync_status' => 'synced',
                'third_party_account_id' => 'pod-1',
                'remark' => '',
                'config_json' => '{}',
            ],
        ]);
        $service = new \app\service\GameAccountLogService($repository);
        $lines = [];
        for ($i = 1; $i <= 2502; $i++) {
            $lines[] = "line {$i}";
        }

        $result = $service->appendFromThirdParty(3, $lines);

        $this->assertSame(2500, $result['data']['count']);
        $this->assertSame('line 3', $result['data']['logs'][0]);
        $this->assertSame('line 2502', $result['data']['logs'][2499]);
    }

    public function testThirdPartyConfigExportReturnsSavedJson(): void
    {
        $config = [
            'basic' => [
                'debug' => false,
                'reputation' => [
                    'enabled' => true,
                    'threshold' => 80,
                ],
                'reconnectInterval' => 5,
            ],
        ];
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player - 本地预览区服',
                'game_username' => 'any-player',
                'channel_code' => 'official_app',
                'server_id' => 'preview',
                'server_name' => '本地预览区服',
                'status' => 'local_preview',
                'sync_status' => 'local_unsynced',
                'remark' => '第三方接口未配置，本地预览帐号',
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => '2026-07-02 12:00:00',
            ],
        ]);
        $service = new GameAccountService($repository, ['enabled' => true]);

        $result = $service->configForThirdParty(3);

        $this->assertSame(0, $result['code']);
        $this->assertSame('any-player - 本地预览区服', $result['data']['account']['display_name']);
        $this->assertSame($config, $result['data']['config']);
        $this->assertSame('local_unsynced', $result['data']['sync_status']);
        $this->assertSame('2026-07-02 12:00:00', $result['data']['updated_at']);
    }
}
