<?php

namespace tests\Feature;

use app\exception\ApiException;
use app\process\GameAccountLoginValidationWatcher;
use app\service\CredentialCipher;
use app\service\GameAccountLoginValidationService;
use PHPUnit\Framework\TestCase;
use tests\Support\ArrayGameAccountLoginValidationStore;
use tests\Support\ArrayGameAccountRepository;
use tests\Support\ArrayThirdPartyScriptConnectionStore;
use tests\Support\ArrayThirdPartyScriptRuntime;

class GameAccountLoginValidationServiceTest extends TestCase
{
    public function testAccountPasswordIsValidatedBeforeAccountIsCreated(): void
    {
        [$service, $accounts, $runtime, $store] = $this->service();

        $result = $service->begin(7, [
            'login_method' => 1,
            'game_username' => 'player001',
            'game_password' => 'secret-password',
        ]);

        $this->assertSame('verifying', $result['data']['status']);
        $this->assertCount(0, $accounts->listByUserId(7));
        $this->assertSame(1, $runtime->validations[0]['login_method']);
        $this->assertSame('player001', $runtime->validations[0]['identity']);
        $job = $store->jobs[$result['data']['validation_id']];
        $this->assertArrayNotHasKey('credential', $job);
        $this->assertStringNotContainsString('secret-password', $job['credential_cipher']);
    }

    public function testSuccessfulResponseCreatesExactlyOneAccountAndSavesServerName(): void
    {
        [$service, $accounts, , $store] = $this->service();
        $started = $service->begin(7, [
            'login_method' => 2,
            'game_uid' => 'facebook-uid',
            'token' => 'facebook-token',
        ]);
        $job = $store->jobs[$started['data']['validation_id']];

        $this->assertTrue($service->completeFromThirdParty('client-validation', [
            'type' => 'login',
            'request_id' => $job['request_id'],
            'session_id' => $job['session_id'],
            'code' => 1,
            'server_name' => 'VN-202',
            'msg' => '登录成功',
        ], $this->connectionState($job)));

        $rows = $accounts->listByUserId(7);
        $this->assertCount(1, $rows);
        $this->assertSame('VN-202', $rows[0]['server_name']);
        $this->assertSame('facebook-uid', $rows[0]['game_uid']);
        $this->assertSame('facebook-token', (new CredentialCipher('test-key'))->decrypt($rows[0]['game_token_cipher']));
        $status = $service->status(7, $job['validation_id']);
        $this->assertSame('success', $status['data']['status']);
        $this->assertSame((int)$rows[0]['id'], $status['data']['account']['id']);
        $this->assertArrayNotHasKey('game_token_cipher', $status['data']['account']);
        $this->assertArrayNotHasKey('credential_cipher', $store->jobs[$job['validation_id']]);

        $this->assertFalse($service->completeFromThirdParty('client-validation', [
            'request_id' => $job['request_id'],
            'session_id' => $job['session_id'],
            'code' => 1,
            'server_name' => 'VN-202',
            'msg' => 'duplicate',
        ], $this->connectionState($job)));
        $this->assertCount(1, $accounts->listByUserId(7));
    }

    public function testRejectedResponseDoesNotCreateAccount(): void
    {
        [$service, $accounts, , $store] = $this->service();
        $started = $service->begin(7, ['login_method' => 3, 'game_uid' => 'uid', 'token' => 'bad-token']);
        $job = $store->jobs[$started['data']['validation_id']];

        $service->completeFromThirdParty('client-validation', [
            'request_id' => $job['request_id'],
            'session_id' => $job['session_id'],
            'code' => 0,
            'server_name' => '',
            'msg' => 'Token无效',
        ], $this->connectionState($job));

        $this->assertCount(0, $accounts->listByUserId(7));
        $status = $service->status(7, $job['validation_id']);
        $this->assertSame('rejected', $status['data']['status']);
        $this->assertSame('Token无效', $status['data']['message']);
    }

    public function testSameCredentialsReuseValidationAndDifferentActiveCredentialsConflict(): void
    {
        [$service, , $runtime] = $this->service();
        $payload = ['login_method' => 1, 'game_username' => 'player', 'game_password' => 'password'];
        $first = $service->begin(7, $payload);
        $second = $service->begin(7, $payload);
        $this->assertSame($first['data']['validation_id'], $second['data']['validation_id']);
        $this->assertCount(1, $runtime->validations);

        $this->expectException(ApiException::class);
        $service->begin(7, ['login_method' => 1, 'game_username' => 'other', 'game_password' => 'password']);
    }

    public function testOtherUserCannotReadValidation(): void
    {
        [$service] = $this->service();
        $started = $service->begin(7, ['login_method' => 1, 'game_username' => 'player', 'game_password' => 'password']);
        $this->expectException(ApiException::class);
        $service->status(8, $started['data']['validation_id']);
    }

    public function testNoIdleConnectionDoesNotLeaveValidationJob(): void
    {
        $accounts = new ArrayGameAccountRepository([]);
        $runtime = new ArrayThirdPartyScriptRuntime(false);
        $store = new ArrayGameAccountLoginValidationStore();
        $service = new GameAccountLoginValidationService($accounts, $this->config(), 'zh_CN', $runtime, $store);
        try {
            $service->begin(7, ['login_method' => 1, 'game_username' => 'player', 'game_password' => 'password']);
            $this->fail('Expected no idle connection error');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getApiCode());
        }
        $this->assertSame([], $store->jobs);
    }

    public function testTimeoutMarksJobAndClosesMatchingConnection(): void
    {
        [$service, , , $store] = $this->service();
        $started = $service->begin(7, ['login_method' => 1, 'game_username' => 'player', 'game_password' => 'password']);
        $job = $store->jobs[$started['data']['validation_id']];
        $connections = new ArrayThirdPartyScriptConnectionStore();
        $connections->registerIdle('client-validation');
        $connections->allocateIdleForValidation($job['validation_id'], $job['session_id'], $job['request_id']);
        $closed = [];
        $watcher = new GameAccountLoginValidationWatcher($store, $connections, function (string $clientId) use (&$closed, $connections): void {
            $closed[] = $clientId;
            $connections->releaseClient($clientId);
        });

        $watcher->tick($job['expires_at']);

        $this->assertSame('timeout', $store->jobs[$job['validation_id']]['status']);
        $this->assertSame(['client-validation'], $closed);
        $this->assertNull($connections->connection('client-validation'));
    }

    public function testAccountLimitIsCheckedAgainWhenSuccessfulResponseArrives(): void
    {
        [$service, $accounts, , $store] = $this->service();
        $started = $service->begin(7, ['login_method' => 1, 'game_username' => 'pending', 'game_password' => 'password']);
        $job = $store->jobs[$started['data']['validation_id']];
        for ($index = 1; $index <= 3; $index++) {
            $accounts->createLocalPreviewWithinLimit(7, [
                'channel_code' => 'official_app',
                'login_method' => 1,
                'game_username' => 'existing-' . $index,
                'game_uid' => '',
                'game_password_cipher' => 'cipher',
                'game_token_cipher' => null,
                'server_id' => '',
                'server_name' => '',
                'display_name' => 'existing-' . $index,
                'remark' => '',
            ], 3);
        }

        $service->completeFromThirdParty('client-validation', [
            'request_id' => $job['request_id'],
            'session_id' => $job['session_id'],
            'code' => 1,
            'server_name' => 'VN-202',
            'msg' => '登录成功',
        ], $this->connectionState($job));

        $this->assertCount(3, $accounts->listByUserId(7));
        $this->assertSame('error', $service->status(7, $job['validation_id'])['data']['status']);
    }

    public function testDeletedSuccessfulAccountDoesNotLeaveReusableStaleValidation(): void
    {
        [$service, $accounts, , $store] = $this->service();
        $payload = ['login_method' => 1, 'game_username' => 'player', 'game_password' => 'password'];
        $started = $service->begin(7, $payload);
        $job = $store->jobs[$started['data']['validation_id']];
        $service->completeFromThirdParty('client-validation', [
            'request_id' => $job['request_id'],
            'session_id' => $job['session_id'],
            'code' => 1,
            'server_name' => 'VN-202',
            'msg' => '登录成功',
        ], $this->connectionState($job));
        $account = $accounts->listByUserId(7)[0];
        $accounts->deleteForUser(7, (int)$account['id']);

        $retried = $service->begin(7, $payload);

        $this->assertNotSame($started['data']['validation_id'], $retried['data']['validation_id']);
        $this->assertSame('verifying', $retried['data']['status']);
    }

    private function service(): array
    {
        $accounts = new ArrayGameAccountRepository([]);
        $runtime = new ArrayThirdPartyScriptRuntime();
        $store = new ArrayGameAccountLoginValidationStore();
        return [
            new GameAccountLoginValidationService($accounts, $this->config(), 'zh_CN', $runtime, $store),
            $accounts,
            $runtime,
            $store,
        ];
    }

    private function config(): array
    {
        return [
            'enabled' => true,
            'transport' => 'websocket',
            'script_token' => 'script-token',
            'credential_key' => 'test-key',
            'max_accounts_per_user' => 3,
            'facebook_login_enabled' => true,
            'google_login_enabled' => true,
        ];
    }

    private function connectionState(array $job): array
    {
        return [
            'state' => 'validating',
            'validation_id' => $job['validation_id'],
            'request_id' => $job['request_id'],
            'session_id' => $job['session_id'],
        ];
    }
}
