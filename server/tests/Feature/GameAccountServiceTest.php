<?php

namespace tests\Feature;

use app\service\GameAccountService;
use app\service\GameAccountResourceService;
use app\service\GameAccountTaskStateService;
use PHPUnit\Framework\TestCase;
use tests\Support\ArrayGameAccountRepository;
use tests\Support\ArrayGameAccountTaskStatePendingStore;
use tests\Support\ArrayGameAccountRuntimeResourceStore;
use tests\Support\ArrayGameAccountLoginValidationStore;
use tests\Support\ArrayThirdPartyScriptRuntime;

class GameAccountServiceTest extends TestCase
{
    public function testCreateEndpointDelegatesToLoginValidationWithoutPersistingAccount(): void
    {
        $repository = new ArrayGameAccountRepository([]);
        $runtime = new ArrayThirdPartyScriptRuntime();
        $service = new GameAccountService(
            $repository,
            [
                'enabled' => true,
                'transport' => 'websocket',
                'script_token' => 'script-token',
                'credential_key' => 'test-key',
            ],
            'zh_CN',
            $runtime,
            null,
            null,
            null,
            new ArrayGameAccountLoginValidationStore()
        );

        $result = $service->createFromLogin(7, [
            'login_method' => 1,
            'game_username' => 'player',
            'game_password' => 'password',
        ]);

        $this->assertSame('verifying', $result['data']['status']);
        $this->assertCount(0, $repository->listByUserId(7));
        $this->assertCount(1, $runtime->validations);
    }

    public function testEmptyGameAccountListIsReturnedAsRealEmptyState(): void
    {
        $service = new GameAccountService(new ArrayGameAccountRepository([]));

        $result = $service->listForUser(1);

        $this->assertSame(0, $result['code']);
        $this->assertSame([], $result['data']['items']);
        $this->assertSame('未添加游戏账号', $result['data']['empty_text']);
        $this->assertSame(0, $result['data']['account_count']);
        $this->assertSame(3, $result['data']['account_limit']);
        $this->assertTrue($result['data']['can_add_account']);
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

    public function testGameAccountListReturnsRuntimeResourcesFromStatusSnapshot(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'running-player',
                'game_username' => 'running-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'running',
                'sync_status' => 'synced',
                'remark' => '',
                'config_json' => '{}',
            ],
        ]);
        $store = new ArrayGameAccountRuntimeResourceStore();
        $store->save(3, [
            'level' => 14,
            'water' => 1,
            'diamond' => 754,
            'coin' => 236000,
        ]);
        $service = new GameAccountService(
            $repository,
            ['enabled' => true],
            \app\support\I18n::DEFAULT_LOCALE,
            null,
            new GameAccountResourceService($store)
        );

        $result = $service->listForUser(7);

        $resources = $result['data']['items'][0]['resources'];
        $this->assertSame(14, $resources['level']);
        $this->assertSame(1, $resources['water']);
        $this->assertSame(754, $resources['diamond']);
        $this->assertSame(236000, $resources['coin']);
        $this->assertSame(0, $resources['speedCard']);
    }

    public function testDisabledSocialLoginOnlyRejectsNewAccount(): void
    {
        $service = new GameAccountService(new ArrayGameAccountRepository([]), [
            'enabled' => false,
            'credential_key' => 'test-key',
            'facebook_login_enabled' => false,
            'google_login_enabled' => true,
        ]);

        $this->assertSame([1, 3], $service->listForUser(7)['data']['supported_login_methods']);
        $this->expectException(\app\exception\ApiException::class);
        $this->expectExceptionMessage('该登录方式当前已关闭，暂不能新增账号');
        $service->createFromLogin(7, [
            'login_method' => 2,
            'game_uid' => 'uid-facebook',
            'token' => 'token-facebook',
        ]);
    }

    public function testSocialTokenCanBeUpdatedWhileCreationSwitchIsDisabled(): void
    {
        $repository = new ArrayGameAccountRepository([[
            'id' => 3,
            'user_id' => 7,
            'display_name' => 'uid-facebook',
            'login_method' => 2,
            'game_uid' => 'uid-facebook',
            'game_username' => '',
            'game_password_cipher' => null,
            'game_token_cipher' => (new \app\service\CredentialCipher('test-key'))->encrypt('old-token'),
            'channel_code' => 'official_app',
            'status' => 'stopped',
            'sync_status' => 'local_unsynced',
            'config_json' => '{}',
        ]]);
        $runtime = new ArrayThirdPartyScriptRuntime();
        $store = new ArrayGameAccountLoginValidationStore();
        $service = new GameAccountService(
            $repository,
            [
                'enabled' => true,
                'transport' => 'websocket',
                'script_token' => 'script-token',
                'credential_key' => 'test-key',
                'facebook_login_enabled' => false,
            ],
            'zh_CN',
            $runtime,
            null,
            null,
            null,
            $store
        );

        $result = $service->updateCredential(7, 3, ['token' => 'new-token']);

        $this->assertSame(0, $result['code']);
        $this->assertSame('verifying', $result['data']['status']);
        $this->assertSame('credential_update', $result['data']['purpose']);
        $stored = $repository->findById(3);
        $this->assertSame('old-token', (new \app\service\CredentialCipher('test-key'))->decrypt($stored['game_token_cipher']));
        $this->assertSame('new-token', $runtime->validations[0]['credential']);
    }

    public function testCredentialValidationBlocksStartAndDeleteForTargetAccount(): void
    {
        $repository = new ArrayGameAccountRepository([[
            'id' => 3,
            'user_id' => 7,
            'display_name' => 'player',
            'login_method' => 1,
            'game_username' => 'player',
            'game_uid' => '',
            'game_password_cipher' => (new \app\service\CredentialCipher('test-key'))->encrypt('old-password'),
            'game_token_cipher' => null,
            'channel_code' => 'official_app',
            'status' => 'stopped',
            'desired_running' => 0,
            'expire_time' => '2099-01-01 00:00:00',
            'sync_status' => 'local_unsynced',
            'config_json' => '{}',
        ]]);
        $runtime = new ArrayThirdPartyScriptRuntime();
        $store = new ArrayGameAccountLoginValidationStore();
        $service = new GameAccountService(
            $repository,
            [
                'enabled' => true,
                'transport' => 'websocket',
                'script_token' => 'script-token',
                'credential_key' => 'test-key',
            ],
            'zh_CN',
            $runtime,
            null,
            null,
            null,
            $store
        );
        $service->updatePassword(7, 3, 'new-password');

        foreach (['start', 'delete'] as $operation) {
            try {
                $service->{$operation}(7, 3);
                $this->fail($operation . ' should be blocked while credential validation is active');
            } catch (\app\exception\ApiException $e) {
                $this->assertSame(409, $e->getApiCode());
                $this->assertStringContainsString('正在验证新凭证', $e->getMessage());
            }
        }
        $this->assertNotNull($repository->findById(3));
    }

    public function testCreateSocialAccountRequiresUidAndToken(): void
    {
        $service = new GameAccountService(new ArrayGameAccountRepository([]), [
            'enabled' => false,
            'credential_key' => 'test-key',
        ]);

        $this->expectException(\app\exception\ApiException::class);
        $this->expectExceptionMessage('请输入游戏UID和Token');
        $service->createFromLogin(7, [
            'login_method' => 2,
            'game_uid' => 'uid-facebook',
            'token' => '',
        ]);
    }

    public function testLegacyPasswordEndpointRejectsSocialAccount(): void
    {
        $repository = new ArrayGameAccountRepository([[
            'id' => 3,
            'user_id' => 7,
            'display_name' => 'uid-google',
            'login_method' => 3,
            'game_uid' => 'uid-google',
            'game_username' => '',
            'game_password_cipher' => null,
            'game_token_cipher' => (new \app\service\CredentialCipher('test-key'))->encrypt('google-token'),
            'channel_code' => 'official_app',
            'status' => 'stopped',
            'sync_status' => 'local_unsynced',
            'config_json' => '{}',
        ]]);
        $service = new GameAccountService($repository, [
            'enabled' => false,
            'credential_key' => 'test-key',
        ]);

        $this->expectException(\app\exception\ApiException::class);
        $this->expectExceptionMessage('该账号不是账号密码登录，请更新Token');
        $service->updatePassword(7, 3, 'new-password');
    }

    public function testCreateRejectsInvalidLoginMethod(): void
    {
        $service = new GameAccountService(new ArrayGameAccountRepository([]), [
            'enabled' => false,
            'credential_key' => 'test-key',
        ]);

        $this->expectException(\app\exception\ApiException::class);
        $this->expectExceptionMessage('游戏账号登录方式无效');
        $service->createFromLogin(7, ['login_method' => 9, 'game_uid' => 'uid', 'token' => 'token']);
    }

    public function testCreateGameAccountRequiresCredentialKey(): void
    {
        $service = new GameAccountService(new ArrayGameAccountRepository([]), [
            'enabled' => true,
            'transport' => 'websocket',
            'script_token' => 'script-token',
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

    public function testCreateGameAccountRejectsUnsupportedChannel(): void
    {
        $service = new GameAccountService(new ArrayGameAccountRepository([]), [
            'enabled' => false,
            'credential_key' => 'test-key',
        ]);

        $this->expectException(\app\exception\ApiException::class);
        $this->expectExceptionMessage('当前只支持 APP 渠道');

        $service->createFromLogin(7, [
            'channel_code' => 'alipay',
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
                'remark' => '服务器未配置，本地预览账号',
                'config_json' => '{}',
                'expire_time' => '2099-01-01 00:00:00',
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
        $this->assertSame('本地配置已保存，服务器同步未启用', $result['msg']);
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
                'game_password_cipher' => (new \app\service\CredentialCipher('test-key'))->encrypt('secret-password'),
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'local_unsynced',
                'remark' => '服务器未配置，本地预览账号',
                'config_json' => '{}',
                'expire_time' => '2099-01-01 00:00:00',
            ],
        ]);
        $service = new GameAccountService($repository, ['enabled' => false, 'credential_key' => 'test-key']);

        $this->expectException(\app\exception\ApiException::class);
        $this->expectExceptionMessage('服务器未启用，请联系管理员');

        $service->start(7, 3);
    }

    public function testStartUsesIdleScriptConnectionAndMarksAccountStarting(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'game_username' => 'any-player',
                'game_password_cipher' => (new \app\service\CredentialCipher('test-key'))->encrypt('secret-password'),
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'local_preview',
                'sync_status' => 'local_unsynced',
                'third_party_account_id' => '',
                'remark' => '服务器未配置，本地预览账号',
                'config_json' => '{}',
                'expire_time' => '2099-01-01 00:00:00',
            ],
        ]);
        $runtime = new ArrayThirdPartyScriptRuntime();
        $service = new GameAccountService($repository, [
            'enabled' => true,
            'transport' => 'websocket',
            'script_token' => 'script-token',
            'credential_key' => 'test-key',
        ], \app\support\I18n::DEFAULT_LOCALE, $runtime);

        $result = $service->start(7, 3);

        $this->assertSame(0, $result['code']);
        $this->assertSame('starting', $result['data']['account']['status']);
        $this->assertSame(3, $runtime->started[0]['account_id']);
        $this->assertSame('secret-password', $runtime->started[0]['game_password']);
        $this->assertSame([], $runtime->started[0]['config']);
        $this->assertFalse($runtime->started[0]['task_state']['exists']);
        $this->assertSame(1, (int)$repository->findById(3)['desired_running']);
        $this->assertSame('启动任务已提交，等待服务器确认', $result['msg']);
    }

    public function testExistingFacebookAccountStartsWithDecryptedTokenWhenCreationIsDisabled(): void
    {
        $repository = new ArrayGameAccountRepository([[
            'id' => 3,
            'user_id' => 7,
            'display_name' => 'uid-facebook',
            'login_method' => 2,
            'game_uid' => 'uid-facebook',
            'game_username' => '',
            'game_password_cipher' => null,
            'game_token_cipher' => (new \app\service\CredentialCipher('test-key'))->encrypt('facebook-token'),
            'channel_code' => 'official_app',
            'server_id' => '',
            'server_name' => '',
            'status' => 'stopped',
            'sync_status' => 'local_unsynced',
            'third_party_account_id' => '',
            'remark' => '',
            'config_json' => '{}',
            'expire_time' => '2099-01-01 00:00:00',
        ]]);
        $runtime = new ArrayThirdPartyScriptRuntime();
        $service = new GameAccountService($repository, [
            'enabled' => true,
            'transport' => 'websocket',
            'script_token' => 'script-token',
            'credential_key' => 'test-key',
            'facebook_login_enabled' => false,
        ], \app\support\I18n::DEFAULT_LOCALE, $runtime);

        $service->start(7, 3);

        $this->assertSame(2, $runtime->started[0]['login_method']);
        $this->assertSame('facebook-token', $runtime->started[0]['credential']);
    }

    public function testStartSendsLatestTaskStateSnapshot(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'game_username' => 'any-player',
                'game_password_cipher' => (new \app\service\CredentialCipher('test-key'))->encrypt('secret-password'),
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'stopped',
                'sync_status' => 'local_unsynced',
                'third_party_account_id' => '',
                'remark' => '',
                'config_json' => '{}',
                'expire_time' => '2099-01-01 00:00:00',
            ],
        ]);
        $taskStates = $this->taskStates($repository);
        $taskStates->persistSnapshots([[
            'game_account_id' => 3,
            'state_json' => '{"step":2}',
            'state_hash' => hash('sha256', '{"step":2}'),
            'state_bytes' => strlen('{"step":2}'),
            'saved_at' => '2026-07-09 12:00:00',
        ]]);
        $runtime = new ArrayThirdPartyScriptRuntime();
        $service = new GameAccountService($repository, [
            'enabled' => true,
            'transport' => 'websocket',
            'script_token' => 'script-token',
            'credential_key' => 'test-key',
        ], \app\support\I18n::DEFAULT_LOCALE, $runtime, taskStates: $taskStates);

        $service->start(7, 3);

        $this->assertTrue($runtime->started[0]['task_state']['exists']);
        $this->assertSame(2, $runtime->started[0]['task_state']['state']->step);
        $this->assertSame('2026-07-09 12:00:00', $runtime->started[0]['task_state']['saved_at']);
    }

    public function testStartClearsPreviousRuntimeResourcesBeforeNewSession(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'game_username' => 'any-player',
                'game_password_cipher' => (new \app\service\CredentialCipher('test-key'))->encrypt('secret-password'),
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'running',
                'sync_status' => 'synced',
                'third_party_account_id' => '',
                'remark' => '',
                'config_json' => '{}',
                'expire_time' => '2099-01-01 00:00:00',
            ],
        ]);
        $store = new ArrayGameAccountRuntimeResourceStore();
        $store->save(3, ['level' => 14, 'coin' => 236000]);
        $service = new GameAccountService(
            $repository,
            [
                'enabled' => true,
                'transport' => 'websocket',
                'script_token' => 'script-token',
                'credential_key' => 'test-key',
            ],
            \app\support\I18n::DEFAULT_LOCALE,
            new ArrayThirdPartyScriptRuntime(),
            new GameAccountResourceService($store),
            taskStates: $this->taskStates($repository)
        );

        $result = $service->start(7, 3);

        $this->assertSame('starting', $result['data']['account']['status']);
        $this->assertSame([3], $store->cleared);
        $this->assertNull($store->get(3));
    }

    public function testStartFailsWithoutIdleScriptConnectionAndKeepsAccountStopped(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'game_username' => 'any-player',
                'game_password_cipher' => (new \app\service\CredentialCipher('test-key'))->encrypt('secret-password'),
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'stopped',
                'sync_status' => 'local_unsynced',
                'third_party_account_id' => '',
                'remark' => '',
                'config_json' => '{}',
                'expire_time' => '2099-01-01 00:00:00',
            ],
        ]);
        $service = new GameAccountService($repository, [
            'enabled' => true,
            'transport' => 'websocket',
            'script_token' => 'script-token',
            'credential_key' => 'test-key',
        ], \app\support\I18n::DEFAULT_LOCALE, new ArrayThirdPartyScriptRuntime(false), taskStates: $this->taskStates($repository));

        try {
            $service->start(7, 3);
            $this->fail('Expected start without idle script connection to fail.');
        } catch (\app\exception\ApiException $exception) {
            $this->assertSame('服务器未准备好，请联系管理员', $exception->getMessage());
            $this->assertSame('stopped', $repository->findById(3)['status']);
        }
    }

    public function testStartFailsWhenAccountHasNoQuotaExpiryWithoutReservingRuntime(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'game_username' => 'any-player',
                'game_password_cipher' => (new \app\service\CredentialCipher('test-key'))->encrypt('secret-password'),
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'stopped',
                'sync_status' => 'local_unsynced',
                'third_party_account_id' => '',
                'remark' => '',
                'config_json' => '{}',
                'expire_time' => null,
            ],
        ]);
        $runtime = new ArrayThirdPartyScriptRuntime();
        $service = new GameAccountService($repository, [
            'enabled' => true,
            'transport' => 'websocket',
            'script_token' => 'script-token',
            'credential_key' => 'test-key',
        ], \app\support\I18n::DEFAULT_LOCALE, $runtime, taskStates: $this->taskStates($repository));

        $this->expectException(\app\exception\ApiException::class);
        $this->expectExceptionMessage('游戏账号配额未配置或已到期');

        try {
            $service->start(7, 3);
        } finally {
            $this->assertSame([], $runtime->started);
            $this->assertSame('stopped', $repository->findById(3)['status']);
        }
    }

    public function testStartFailsWhenQuotaExpiredWithoutChangingState(): void
    {
        $repository = new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'game_username' => 'any-player',
                'game_password_cipher' => (new \app\service\CredentialCipher('test-key'))->encrypt('secret-password'),
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'stopped',
                'sync_status' => 'local_unsynced',
                'third_party_account_id' => '',
                'remark' => '',
                'config_json' => '{}',
                'expire_time' => '2000-01-01 00:00:00',
            ],
        ]);
        $runtime = new ArrayThirdPartyScriptRuntime();
        $service = new GameAccountService($repository, [
            'enabled' => true,
            'transport' => 'websocket',
            'script_token' => 'script-token',
            'credential_key' => 'test-key',
        ], \app\support\I18n::DEFAULT_LOCALE, $runtime, taskStates: $this->taskStates($repository));

        $this->expectException(\app\exception\ApiException::class);
        $this->expectExceptionMessage('游戏账号配额未配置或已到期');

        try {
            $service->start(7, 3);
        } finally {
            $this->assertSame([], $runtime->started);
            $this->assertSame('stopped', $repository->findById(3)['status']);
        }
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
        $repository->appendNormalLogLines(3, 'session-1', ['line 1'], 2500);
        $runtime = new ArrayThirdPartyScriptRuntime();
        $service = new GameAccountService($repository, ['enabled' => true], \app\support\I18n::DEFAULT_LOCALE, $runtime);

        $result = $service->stop(7, 3);

        $this->assertSame(0, $result['code']);
        $this->assertSame('stopping', $result['data']['account']['status']);
        $this->assertSame('local_unsynced', $result['data']['account']['sync_status']);
        $this->assertSame('session-1', $result['data']['account']['log_session_id']);
        $this->assertSame(0, (int)$repository->findById(3)['desired_running']);
        $this->assertSame(3, $runtime->stopped[0]['account_id']);
        $this->assertSame(1, $repository->countNormalLogLines(3, 'session-1'));
    }

    public function testStopClearsRuntimeResources(): void
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
        $store = new ArrayGameAccountRuntimeResourceStore();
        $store->save(3, ['level' => 14]);
        $service = new GameAccountService(
            $repository,
            ['enabled' => true],
            \app\support\I18n::DEFAULT_LOCALE,
            new ArrayThirdPartyScriptRuntime(),
            new GameAccountResourceService($store)
        );

        $service->stop(7, 3);

        $this->assertSame([3], $store->cleared);
        $this->assertNull($store->get(3));
    }

    public function testDeleteClearsRuntimeResources(): void
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
                'status' => 'stopped',
                'sync_status' => 'local_unsynced',
                'third_party_account_id' => '',
                'remark' => '',
                'config_json' => '{}',
                'expire_time' => '2099-01-01 00:00:00',
            ],
        ]);
        $store = new ArrayGameAccountRuntimeResourceStore();
        $store->save(3, ['level' => 14]);
        $service = new GameAccountService(
            $repository,
            ['enabled' => true],
            \app\support\I18n::DEFAULT_LOCALE,
            null,
            new GameAccountResourceService($store)
        );

        $service->delete(7, 3);

        $this->assertSame([3], $store->cleared);
        $this->assertNull($store->get(3));
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
                'log_session_id' => 'session-1',
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
                'remark' => '服务器未配置，本地预览账号',
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => '2026-07-02 12:00:00',
            ],
        ]);
        $service = new GameAccountService($repository, ['enabled' => true]);

        $result = $service->configForThirdParty(3);

        $this->assertSame(0, $result['code']);
        $this->assertSame('any-player - 本地预览区服', $result['data']['account']['display_name']);
        $this->assertSame($config, $result['data']['config']);
        $this->assertArrayNotHasKey('ui_hidden_paths', $result['data']);
        $this->assertSame('local_unsynced', $result['data']['sync_status']);
        $this->assertSame('2026-07-02 12:00:00', $result['data']['updated_at']);
    }

    private function taskStates(ArrayGameAccountRepository $repository): GameAccountTaskStateService
    {
        return new GameAccountTaskStateService($repository, 1024, pendingStore: new ArrayGameAccountTaskStatePendingStore());
    }
}
