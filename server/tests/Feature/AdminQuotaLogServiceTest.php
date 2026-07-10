<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;
use plugin\admin\app\service\GameAssistQuotaLogAdminService;
use support\Db;

class AdminQuotaLogServiceTest extends TestCase
{
    public function testGrantRecordsShowAdminUserPointsBalanceAndRemark(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$userId, $suffix] = $this->createUser();
            $adminId = $this->createAdmin($suffix);
            Db::table('ga_admin_operation_logs')->insert([
                'admin_id' => $adminId,
                'action' => 'gameassist_user.grant_quota',
                'target_type' => 'ga_users',
                'target_id' => (string)$userId,
                'payload' => json_encode(['points' => 8, 'balance_after' => '10.00', 'remark' => '人工补偿'], JSON_UNESCAPED_UNICODE),
                'created_at' => '2026-07-10 10:00:00',
            ]);

            $result = (new GameAssistQuotaLogAdminService())->grantRecords([
                'admin_account' => 'admin_' . $suffix,
                'user_account' => 'quota_log_' . $suffix,
                'created_at' => ['2026-07-10 00:00:00', '2026-07-10 23:59:59'],
                'page' => 1,
                'limit' => 20,
            ]);

            $this->assertSame(1, $result['count']);
            $this->assertSame($adminId, $result['data'][0]['admin_id']);
            $this->assertSame($userId, $result['data'][0]['user_id']);
            $this->assertSame(8, $result['data'][0]['points']);
            $this->assertSame('10.00', $result['data'][0]['balance_after']);
            $this->assertSame('人工补偿', $result['data'][0]['remark']);
            $this->assertFalse($result['data'][0]['payload_invalid']);
        } finally {
            $connection->rollBack();
        }
    }

    public function testGrantRecordMarksInvalidPayloadAndDeletedObjects(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $logId = (int)Db::table('ga_admin_operation_logs')->insertGetId([
                'admin_id' => 99999999,
                'action' => 'gameassist_user.grant_quota',
                'target_type' => 'ga_users',
                'target_id' => '88888888',
                'payload' => '{"points":0}',
                'created_at' => '2026-07-10 10:00:00',
            ]);

            $result = (new GameAssistQuotaLogAdminService())->grantRecords(['page' => 1, 'limit' => 20]);
            $row = $this->findById($result['data'], $logId);

            $this->assertFalse($row['admin_exists']);
            $this->assertFalse($row['user_exists']);
            $this->assertTrue($row['payload_invalid']);
            $this->assertNull($row['points']);
            $this->assertSame('日志内容异常', $row['remark']);
        } finally {
            $connection->rollBack();
        }
    }

    public function testConsumeRecordsShowGameAccountAndKeepHistoryAfterDeletion(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$userId, $suffix] = $this->createUser();
            $gameAccountId = $this->createGameAccount($userId, $suffix);
            $transactionId = (int)Db::table('ga_user_point_transactions')->insertGetId([
                'user_id' => $userId,
                'type' => 'quota_consume',
                'amount' => '-11.00',
                'balance_after' => '9.00',
                'description' => '游戏账号role_' . $suffix . '延期12天',
                'related_user_id' => null,
                'related_role_id' => (string)$gameAccountId,
                'ip_address' => '',
                'created_at' => '2026-07-10 11:00:00',
            ]);

            $service = new GameAssistQuotaLogAdminService();
            $result = $service->consumeRecords([
                'user_account' => 'quota_log_' . $suffix,
                'game_account' => 'role_' . $suffix,
                'page' => 1,
                'limit' => 20,
            ]);

            $this->assertSame(1, $result['count']);
            $this->assertSame($gameAccountId, $result['data'][0]['game_account_id']);
            $this->assertSame('role_' . $suffix, $result['data'][0]['game_username']);
            $this->assertSame('11.00', $result['data'][0]['consumed_points']);
            $this->assertFalse($result['data'][0]['amount_invalid']);

            Db::table('ga_game_accounts')->where('id', $gameAccountId)->delete();
            $deletedResult = $service->consumeRecords(['game_account_id' => $gameAccountId, 'page' => 1, 'limit' => 20]);
            $deletedRow = $this->findById($deletedResult['data'], $transactionId);
            $this->assertFalse($deletedRow['game_account_exists']);
            $this->assertSame($gameAccountId, $deletedRow['game_account_id']);
            $this->assertStringContainsString('延期12天', $deletedRow['description']);
        } finally {
            $connection->rollBack();
        }
    }

    public function testConsumeRecordMarksNonNegativeAmountAsInvalid(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$userId] = $this->createUser();
            $transactionId = (int)Db::table('ga_user_point_transactions')->insertGetId([
                'user_id' => $userId,
                'type' => 'quota_consume',
                'amount' => '1.00',
                'balance_after' => '5.00',
                'description' => '异常测试',
                'related_user_id' => null,
                'related_role_id' => '123456789',
                'ip_address' => '',
                'created_at' => '2026-07-10 12:00:00',
            ]);

            $result = (new GameAssistQuotaLogAdminService())->consumeRecords(['user_id' => $userId, 'page' => 1, 'limit' => 20]);
            $row = $this->findById($result['data'], $transactionId);
            $this->assertTrue($row['amount_invalid']);
            $this->assertNull($row['consumed_points']);
        } finally {
            $connection->rollBack();
        }
    }

    private function createUser(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $id = (int)Db::table('ga_users')->insertGetId([
            'account' => 'quota_log_' . $suffix,
            'email' => 'quota_log_' . $suffix . '@example.com',
            'nickname' => 'quota_log_' . $suffix,
            'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
            'balance' => '20.00',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return [$id, $suffix];
    }

    private function createAdmin(string $suffix): int
    {
        $now = date('Y-m-d H:i:s');
        return (int)Db::table('wa_admins')->insertGetId([
            'username' => 'admin_' . $suffix,
            'nickname' => '管理员' . $suffix,
            'password' => password_hash('secret123', PASSWORD_DEFAULT),
            'status' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createGameAccount(int $userId, string $suffix): int
    {
        $now = date('Y-m-d H:i:s');
        return (int)Db::table('ga_game_accounts')->insertGetId([
            'user_id' => $userId,
            'display_name' => '角色' . $suffix,
            'game_username' => 'role_' . $suffix,
            'game_password_cipher' => '',
            'channel_code' => 'official_app',
            'server_id' => '1',
            'server_name' => '测试服',
            'status' => 'stopped',
            'sync_status' => 'local_unsynced',
            'third_party_account_id' => '',
            'log_session_id' => '',
            'desired_running' => 0,
            'expire_time' => null,
            'remark' => '',
            'config_json' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function findById(array $rows, int $id): array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        $this->fail('未找到测试日志ID：' . $id);
    }
}
