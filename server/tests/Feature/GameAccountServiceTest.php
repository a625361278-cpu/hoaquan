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

    public function testGameAccountListMarksAccountsWithSavedConfig(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'configured-player',
                'game_username' => 'configured-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'local_unsynced',
                'remark' => '',
                'config_json' => '{"basic":{"debug":true}}',
            ],
            [
                'id' => 4,
                'user_id' => 7,
                'display_name' => 'empty-player',
                'game_username' => 'empty-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'local_unsynced',
                'remark' => '',
                'config_json' => '{}',
            ],
        ]);
        $service = new GameAccountService($repository, ['enabled' => false]);

        $result = $service->listForUser(7);

        $this->assertTrue($result['data']['items'][0]['has_config']);
        $this->assertFalse($result['data']['items'][1]['has_config']);
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

    public function testImportConfigCopiesAnotherOwnedAccountConfigImmediately(): void
    {
        $sourceConfig = [
            'basic' => [
                'debug' => true,
                'reconnectInterval' => 8,
            ],
        ];
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'target-player',
                'game_username' => 'target-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'synced',
                'remark' => '',
                'config_json' => '{"basic":{"debug":false}}',
            ],
            [
                'id' => 4,
                'user_id' => 7,
                'display_name' => 'source-player',
                'game_username' => 'source-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'local_unsynced',
                'remark' => '',
                'config_json' => json_encode($sourceConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ]);
        $service = new GameAccountService($repository, ['enabled' => false]);

        $result = $service->importConfig(7, 3, 4);

        $this->assertSame(0, $result['code']);
        $this->assertSame($sourceConfig, $result['data']['config']);
        $this->assertSame('local_unsynced', $result['data']['sync_status']);
        $this->assertSame($sourceConfig, json_decode($repository->findByUserId(7, 3)['config_json'], true));
        $this->assertSame('配置已导入', $result['msg']);
    }

    public function testImportConfigRejectsAccountOwnedByAnotherUser(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'target-player',
                'game_username' => 'target-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'synced',
                'remark' => '',
                'config_json' => '{"basic":{"debug":false}}',
            ],
            [
                'id' => 9,
                'user_id' => 8,
                'display_name' => 'other-player',
                'game_username' => 'other-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'local_unsynced',
                'remark' => '',
                'config_json' => '{"basic":{"debug":true}}',
            ],
        ]);
        $service = new GameAccountService($repository, ['enabled' => false]);

        $this->expectException(\app\exception\ApiException::class);
        $this->expectExceptionMessage('来源游戏账号不存在或不属于当前用户');

        $service->importConfig(7, 3, 9);
    }

    public function testImportConfigRejectsSameAccount(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'target-player',
                'game_username' => 'target-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'synced',
                'remark' => '',
                'config_json' => '{"basic":{"debug":false}}',
            ],
        ]);
        $service = new GameAccountService($repository, ['enabled' => false]);

        $this->expectException(\app\exception\ApiException::class);
        $this->expectExceptionMessage('不能从当前游戏账号导入配置');

        $service->importConfig(7, 3, 3);
    }

    public function testImportConfigRejectsEmptySourceConfigWithoutChangingTarget(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'target-player',
                'game_username' => 'target-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'synced',
                'remark' => '',
                'config_json' => '{"basic":{"debug":false}}',
            ],
            [
                'id' => 4,
                'user_id' => 7,
                'display_name' => 'empty-source',
                'game_username' => 'empty-source',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'local_unsynced',
                'remark' => '',
                'config_json' => '{}',
            ],
        ]);
        $service = new GameAccountService($repository, ['enabled' => false]);

        try {
            $service->importConfig(7, 3, 4);
            $this->fail('Expected empty source config to be rejected.');
        } catch (\app\exception\ApiException $exception) {
            $this->assertSame('来源账号暂无可导入配置', $exception->getMessage());
            $this->assertSame(['basic' => ['debug' => false]], json_decode($repository->findByUserId(7, 3)['config_json'], true));
        }
    }

    public function testImportConfigRejectsInvalidSourceConfigWithoutChangingTarget(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'target-player',
                'game_username' => 'target-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'synced',
                'remark' => '',
                'config_json' => '{"basic":{"debug":false}}',
            ],
            [
                'id' => 4,
                'user_id' => 7,
                'display_name' => 'bad-source',
                'game_username' => 'bad-source',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'local_unsynced',
                'remark' => '',
                'config_json' => '{bad-json',
            ],
        ]);
        $service = new GameAccountService($repository, ['enabled' => false]);

        try {
            $service->importConfig(7, 3, 4);
            $this->fail('Expected invalid source config to be rejected.');
        } catch (\app\exception\ApiException $exception) {
            $this->assertSame('来源账号配置数据异常，不能导入', $exception->getMessage());
            $this->assertSame(['basic' => ['debug' => false]], json_decode($repository->findByUserId(7, 3)['config_json'], true));
        }
    }

    public function testImportConfigRejectsJsonListSourceConfigWithoutChangingTarget(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'target-player',
                'game_username' => 'target-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'synced',
                'remark' => '',
                'config_json' => '{"basic":{"debug":false}}',
            ],
            [
                'id' => 4,
                'user_id' => 7,
                'display_name' => 'list-source',
                'game_username' => 'list-source',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'local_unsynced',
                'remark' => '',
                'config_json' => '["not","an","object"]',
            ],
        ]);
        $service = new GameAccountService($repository, ['enabled' => false]);

        try {
            $service->importConfig(7, 3, 4);
            $this->fail('Expected JSON list source config to be rejected.');
        } catch (\app\exception\ApiException $exception) {
            $this->assertSame('来源账号配置数据异常，不能导入', $exception->getMessage());
            $this->assertSame(['basic' => ['debug' => false]], json_decode($repository->findByUserId(7, 3)['config_json'], true));
        }
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
