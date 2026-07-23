<?php

namespace tests\Feature;

use app\exception\ApiException;
use app\service\GameAccountQuotaService;
use PHPUnit\Framework\TestCase;
use support\Db;

class GameAccountQuotaServiceTest extends TestCase
{
    public function testBasePackageConsumesTenPointsAndExtendsElevenDaysFromCurrentExpiry(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$userId, $accountId] = $this->createUserAndAccount('20.00', '2026-07-18 15:58:51');
            $service = new GameAccountQuotaService(static fn (): int => strtotime('2026-07-08 12:00:00'));

            $result = $service->extendAccount($userId, $accountId, 0);

            $this->assertSame('10.00', (string)Db::table('ga_users')->where('id', $userId)->value('balance'));
            $this->assertSame('2026-07-29 15:58:51', (string)Db::table('ga_game_accounts')->where('id', $accountId)->value('expire_time'));
            $this->assertSame(10, $result['cost_points']);
            $this->assertSame(11, $result['add_days']);
            $this->assertSame('10.00', $result['balance']);
            $this->assertSame(1, Db::table('ga_user_point_transactions')->where('user_id', $userId)->where('type', 'quota_consume')->count());
        } finally {
            $connection->rollBack();
        }
    }

    public function testSelectedPackageAndTenExtraPointsGrantPackageAndExtraBonusDays(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$userId, $accountId] = $this->createUserAndAccount('30.00', '2026-07-18 15:58:51');
            $service = new GameAccountQuotaService(static fn (): int => strtotime('2026-07-08 12:00:00'));

            $result = $service->extendAccount($userId, $accountId, 10, true);

            $this->assertSame('10.00', (string)Db::table('ga_users')->where('id', $userId)->value('balance'));
            $this->assertSame('2026-08-09 15:58:51', (string)Db::table('ga_game_accounts')->where('id', $accountId)->value('expire_time'));
            $this->assertSame(20, $result['cost_points']);
            $this->assertSame(22, $result['add_days']);
            $this->assertSame(1, $result['bonus_days']);
            $this->assertTrue($result['package_selected']);
        } finally {
            $connection->rollBack();
        }
    }

    public function testUnselectedPackageCanExtendOneDayWithOnePoint(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$userId, $accountId] = $this->createUserAndAccount('20.00', '2026-07-18 15:58:51');
            $service = new GameAccountQuotaService(static fn (): int => strtotime('2026-07-08 12:00:00'));

            $result = $service->extendAccount($userId, $accountId, 1, false);

            $this->assertSame('19.00', (string)Db::table('ga_users')->where('id', $userId)->value('balance'));
            $this->assertSame('2026-07-19 15:58:51', (string)Db::table('ga_game_accounts')->where('id', $accountId)->value('expire_time'));
            $this->assertSame(1, $result['cost_points']);
            $this->assertSame(1, $result['add_days']);
            $this->assertSame(0, $result['bonus_days']);
            $this->assertFalse($result['package_selected']);
        } finally {
            $connection->rollBack();
        }
    }

    public function testUnselectedPackageAndTenPointsGrantOneBonusDay(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$userId, $accountId] = $this->createUserAndAccount('20.00', '2026-07-18 15:58:51');
            $service = new GameAccountQuotaService(static fn (): int => strtotime('2026-07-08 12:00:00'));

            $result = $service->extendAccount($userId, $accountId, 10, false);

            $this->assertSame('10.00', (string)Db::table('ga_users')->where('id', $userId)->value('balance'));
            $this->assertSame('2026-07-29 15:58:51', (string)Db::table('ga_game_accounts')->where('id', $accountId)->value('expire_time'));
            $this->assertSame(10, $result['cost_points']);
            $this->assertSame(11, $result['add_days']);
            $this->assertSame(1, $result['bonus_days']);
            $this->assertFalse($result['package_selected']);
        } finally {
            $connection->rollBack();
        }
    }

    public function testExpiredAccountExtendsFromCurrentTime(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$userId, $accountId] = $this->createUserAndAccount('20.00', '2026-07-01 00:00:00');
            $service = new GameAccountQuotaService(static fn (): int => strtotime('2026-07-08 12:00:00'));

            $service->extendAccount($userId, $accountId, 0, true);

            $this->assertSame('2026-07-19 12:00:00', (string)Db::table('ga_game_accounts')->where('id', $accountId)->value('expire_time'));
        } finally {
            $connection->rollBack();
        }
    }

    public function testInsufficientBalanceDoesNotChangeAccountOrBalance(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$userId, $accountId] = $this->createUserAndAccount('9.00', '2026-07-18 15:58:51');
            $service = new GameAccountQuotaService(static fn (): int => strtotime('2026-07-08 12:00:00'));

            $this->expectException(ApiException::class);
            $this->expectExceptionMessage('配额余额不足');

            try {
                $service->extendAccount($userId, $accountId, 0);
            } finally {
                $this->assertSame('9.00', (string)Db::table('ga_users')->where('id', $userId)->value('balance'));
                $this->assertSame('2026-07-18 15:58:51', (string)Db::table('ga_game_accounts')->where('id', $accountId)->value('expire_time'));
                $this->assertSame(0, Db::table('ga_user_point_transactions')->where('user_id', $userId)->where('type', 'quota_consume')->count());
            }
        } finally {
            $connection->rollBack();
        }
    }

    public function testRejectsInvalidExtraPoints(): void
    {
        [$userId, $accountId] = [1, 1];
        $service = new GameAccountQuotaService();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('额外配额必须是非负整数');

        $service->extendAccount($userId, $accountId, -1);
    }

    public function testRejectsZeroCostWhenPackageIsNotSelected(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$userId, $accountId] = $this->createUserAndAccount('20.00', '2026-07-18 15:58:51');
            $service = new GameAccountQuotaService(static fn (): int => strtotime('2026-07-08 12:00:00'));

            $this->expectException(ApiException::class);
            $this->expectExceptionMessage('请至少选择1点延期配额');

            try {
                $service->extendAccount($userId, $accountId, 0, false);
            } finally {
                $this->assertSame('20.00', (string)Db::table('ga_users')->where('id', $userId)->value('balance'));
                $this->assertSame('2026-07-18 15:58:51', (string)Db::table('ga_game_accounts')->where('id', $accountId)->value('expire_time'));
                $this->assertSame(0, Db::table('ga_user_point_transactions')->where('user_id', $userId)->where('type', 'quota_consume')->count());
            }
        } finally {
            $connection->rollBack();
        }
    }

    private function createUserAndAccount(string $balance, ?string $expireTime): array
    {
        $now = date('Y-m-d H:i:s');
        $suffix = bin2hex(random_bytes(4));
        $userId = (int)Db::table('ga_users')->insertGetId([
            'account' => 'quota_' . $suffix,
            'email' => 'quota_' . $suffix . '@example.com',
            'nickname' => 'quota_' . $suffix,
            'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
            'balance' => $balance,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $accountId = (int)Db::table('ga_game_accounts')->insertGetId([
            'user_id' => $userId,
            'display_name' => 'quota-player',
            'game_username' => 'quota-player',
            'game_password_cipher' => '',
            'channel_code' => 'official_app',
            'server_id' => '',
            'server_name' => '',
            'status' => 'stopped',
            'sync_status' => 'local_unsynced',
            'third_party_account_id' => '',
            'log_session_id' => '',
            'desired_running' => 0,
            'expire_time' => $expireTime,
            'remark' => '',
            'config_json' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$userId, $accountId];
    }
}
