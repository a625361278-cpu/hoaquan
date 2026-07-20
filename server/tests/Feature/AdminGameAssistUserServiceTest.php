<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;
use plugin\admin\app\service\GameAssistUserAdminService;
use RuntimeException;
use support\Db;

class AdminGameAssistUserServiceTest extends TestCase
{
    public function testSanitizesRowsWithoutPasswordHash(): void
    {
        $service = new GameAssistUserAdminService();

        $rows = $service->sanitizeRows([
            (object)[
                'id' => 1,
                'account' => 'player001',
                'email' => 'player001@example.com',
                'nickname' => '玩家001',
                'password_hash' => 'secret-hash',
                'balance' => '0.00',
                'expire_at' => null,
                'status' => 1,
                'created_at' => '2026-07-01 10:00:00',
                'updated_at' => '2026-07-01 10:00:00',
            ],
        ]);

        $this->assertSame('player001', $rows[0]['account']);
        $this->assertArrayNotHasKey('password_hash', $rows[0]);
    }

    public function testSanitizesModelLikeRowsWithToArray(): void
    {
        $service = new GameAssistUserAdminService();
        $row = new class {
            public function toArray(): array
            {
                return [
                    'id' => 1,
                    'account' => 'player001',
                    'password_hash' => 'secret-hash',
                ];
            }
        };

        $rows = $service->sanitizeRows([$row]);

        $this->assertSame(['id' => 1, 'account' => 'player001'], $rows[0]);
    }

    public function testOnlyStatusCanBeUpdatedFromGenericAdminUpdate(): void
    {
        $service = new GameAssistUserAdminService();

        $filtered = $service->filterStatusUpdate([
            'id' => 1,
            'status' => '0',
            'balance' => '999.00',
            'expire_at' => '2099-01-01',
            'password_hash' => 'plain-text',
        ]);

        $this->assertSame(['status' => 0], $filtered);
    }

    public function testRejectsInvalidStatusUpdate(): void
    {
        $service = new GameAssistUserAdminService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('用户状态值异常');

        $service->filterStatusUpdate(['status' => '2']);
    }

    public function testBuildPasswordHashRejectsShortPassword(): void
    {
        $service = new GameAssistUserAdminService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('密码至少需要6位');

        $service->buildPasswordHash('12345');
    }

    public function testBuildPasswordHashUsesPasswordHash(): void
    {
        $service = new GameAssistUserAdminService();

        $hash = $service->buildPasswordHash('newsecret');

        $this->assertNotSame('newsecret', $hash);
        $this->assertTrue(password_verify('newsecret', $hash));
    }

    public function testAdminGrantQuotaAddsUserBalanceAndWritesLogs(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $now = date('Y-m-d H:i:s');
            $suffix = bin2hex(random_bytes(4));
            $userId = (int)Db::table('ga_users')->insertGetId([
                'account' => 'grant_' . $suffix,
                'email' => 'grant_' . $suffix . '@example.com',
                'nickname' => 'grant_' . $suffix,
                'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
                'balance' => '2.00',
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $service = new GameAssistUserAdminService();

            $result = $service->grantQuota($userId, 8, '人工补偿', 99);

            $this->assertSame('10.00', (string)Db::table('ga_users')->where('id', $userId)->value('balance'));
            $this->assertSame('10.00', $result['balance']);
            $this->assertSame(1, Db::table('ga_user_point_transactions')->where('user_id', $userId)->where('type', 'admin_grant')->count());
            $this->assertSame(1, Db::table('ga_admin_operation_logs')->where('admin_id', 99)->where('action', 'gameassist_user.grant_quota')->count());
        } finally {
            $connection->rollBack();
        }
    }

    public function testAdminGrantQuotaRejectsNonPositivePoints(): void
    {
        $service = new GameAssistUserAdminService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('添加配额必须是正整数');

        $service->grantQuota(1, 0, '', 99);
    }

    public function testGameAccountsReturnBindingInfoAndDoNotExposeSecrets(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $now = date('Y-m-d H:i:s');
            $suffix = bin2hex(random_bytes(4));
            $userId = (int)Db::table('ga_users')->insertGetId([
                'account' => 'accounts_' . $suffix,
                'email' => 'accounts_' . $suffix . '@example.com',
                'nickname' => 'accounts_' . $suffix,
                'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
                'balance' => '2.00',
                'bound_role_id' => 'fb_role_' . $suffix,
                'role_bound_at' => $now,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('ga_game_accounts')->insert([
                'user_id' => $userId,
                'display_name' => 'FB角色',
                'game_username' => '',
                'game_uid' => 'fb_uid_' . $suffix,
                'game_password_cipher' => null,
                'game_token_cipher' => 'secret-token-cipher',
                'channel_code' => 'official_app',
                'login_method' => 2,
                'server_id' => '216',
                'server_name' => 'VN-216',
                'status' => 'running',
                'sync_status' => 'synced',
                'third_party_account_id' => 'fb_role_' . $suffix,
                'log_session_id' => 'session_' . $suffix,
                'desired_running' => 1,
                'expire_time' => '2026-08-01 00:00:00',
                'remark' => '测试',
                'config_json' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $result = (new GameAssistUserAdminService())->gameAccounts($userId, ['page' => 1, 'limit' => 20]);

            $this->assertSame(1, $result['count']);
            $row = $result['data'][0];
            $this->assertSame('Facebook', $row['login_method_label']);
            $this->assertSame('fb_uid_' . $suffix, $row['login_identity']);
            $this->assertSame('fb_role_' . $suffix, $row['bound_role_id']);
            $this->assertSame('fb_role_' . $suffix, $row['third_party_account_id']);
            $this->assertTrue($row['is_bound']);
            $this->assertArrayNotHasKey('game_password_cipher', $row);
            $this->assertArrayNotHasKey('game_token_cipher', $row);
            $this->assertArrayNotHasKey('password_hash', $row);
        } finally {
            $connection->rollBack();
        }
    }

    public function testGameAccountsUseLoginIdentityFallbackWhenThirdPartyRoleMissing(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $now = date('Y-m-d H:i:s');
            $suffix = bin2hex(random_bytes(4));
            $gameUsername = 'role_' . $suffix;
            $userId = (int)Db::table('ga_users')->insertGetId([
                'account' => 'fallback_' . $suffix,
                'email' => 'fallback_' . $suffix . '@example.com',
                'nickname' => 'fallback_' . $suffix,
                'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
                'balance' => '2.00',
                'bound_role_id' => $gameUsername,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('ga_game_accounts')->insert([
                'user_id' => $userId,
                'display_name' => '账号密码角色',
                'game_username' => $gameUsername,
                'game_uid' => '',
                'game_password_cipher' => 'secret-password-cipher',
                'game_token_cipher' => null,
                'channel_code' => 'official_app',
                'login_method' => 1,
                'server_id' => '1',
                'server_name' => '测试服',
                'status' => 'stopped',
                'sync_status' => 'synced',
                'third_party_account_id' => '',
                'log_session_id' => '',
                'desired_running' => 0,
                'expire_time' => null,
                'remark' => '',
                'config_json' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $result = (new GameAssistUserAdminService())->gameAccounts($userId, [
                'game_account' => $gameUsername,
                'login_method' => '1',
                'page' => 1,
                'limit' => 20,
            ]);

            $this->assertSame(1, $result['count']);
            $this->assertSame('账号密码', $result['data'][0]['login_method_label']);
            $this->assertTrue($result['data'][0]['is_bound']);
        } finally {
            $connection->rollBack();
        }
    }
}
