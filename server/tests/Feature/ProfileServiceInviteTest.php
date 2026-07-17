<?php

namespace tests\Feature;

use app\repository\DbUserRepository;
use app\service\ProfileService;
use app\service\SystemSettingService;
use PHPUnit\Framework\TestCase;
use support\Db;

class ProfileServiceInviteTest extends TestCase
{
    public function testProfileInviteReturnsMinimumRoleLevelWithoutLegacyDailyLimit(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$inviterId] = $this->createInvitePair('profile_threshold');
            $settings = new class extends SystemSettingService {
                public function inviteRewardMinRoleLevel(): int
                {
                    return 42;
                }
            };

            $summary = (new ProfileService(new DbUserRepository(), $settings))->summary($inviterId, 'https://example.com');

            $this->assertSame(42, $summary['data']['invite']['min_role_level']);
            $this->assertArrayNotHasKey('daily_limit', $summary['data']['invite']);
        } finally {
            $connection->rollBack();
        }
    }

    public function testStartedAccountOnlyBindsRoleAndDoesNotRewardInvitation(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $now = date('Y-m-d H:i:s');
            $suffix = bin2hex(random_bytes(4));
            $inviterId = Db::table('ga_users')->insertGetId([
                'account' => 'once_inviter_' . $suffix,
                'email' => 'once_inviter_' . $suffix . '@example.com',
                'nickname' => 'once_inviter_' . $suffix,
                'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
                'invite_code' => 'G' . strtoupper(substr($suffix, 0, 5)),
                'balance' => '0.00',
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $inviteeId = Db::table('ga_users')->insertGetId([
                'account' => 'once_invitee_' . $suffix,
                'email' => 'once_invitee_' . $suffix . '@example.com',
                'nickname' => 'once_invitee_' . $suffix,
                'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
                'invite_code' => 'H' . strtoupper(substr($suffix, 0, 5)),
                'invited_by_user_id' => $inviterId,
                'invite_registered_ip' => '10.1.2.3',
                'balance' => '0.00',
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $service = new ProfileService(new DbUserRepository(), new SystemSettingService());
            $account = [
                'id' => 99001,
                'user_id' => $inviteeId,
                'game_username' => 'game_' . $suffix,
            ];
            $payload = [
                'role_id' => 'role_once_' . $suffix,
                'display_name' => 'role-name',
            ];

            $first = $service->bindStartedAccount($account, $payload);
            $second = $service->bindStartedAccount($account, $payload);

            $this->assertFalse($first['data']['rewarded']);
            $this->assertFalse($second['data']['rewarded']);
            $this->assertSame('0.00', (string)Db::table('ga_users')->where('id', $inviterId)->value('balance'));
            $this->assertSame(0, Db::table('ga_user_point_transactions')->where('user_id', $inviterId)->where('type', 'invite_reward')->count());
        } finally {
            $connection->rollBack();
        }
    }

    public function testStartedAccountFallsBackToGameUsernameWhenRoleIdIsMissing(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$inviterId, $inviteeId, $suffix] = $this->createInvitePair('fallback');
            $service = new ProfileService(new DbUserRepository(), new SystemSettingService());

            $result = $service->bindStartedAccount([
                'id' => 99002,
                'user_id' => $inviteeId,
                'game_username' => 'player_' . $suffix . '@example.com',
            ], [
                'display_name' => 'not-unique-display-name',
            ]);

            $boundRoleId = (string)Db::table('ga_users')->where('id', $inviteeId)->value('bound_role_id');
            $this->assertSame('player_' . $suffix . '@example.com', $boundRoleId);
            $this->assertFalse($result['data']['rewarded']);
            $this->assertSame('0.00', (string)Db::table('ga_users')->where('id', $inviterId)->value('balance'));
        } finally {
            $connection->rollBack();
        }
    }

    public function testSameInviteeCannotRewardAgainWithAnotherRoleId(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$inviterId, $inviteeId, $suffix] = $this->createInvitePair('invitee_once');
            $service = new ProfileService(new DbUserRepository(), new SystemSettingService());
            $account = [
                'id' => 99003,
                'user_id' => $inviteeId,
                'game_username' => 'game_' . $suffix,
            ];

            $first = $service->bindStartedAccount($account, ['role_id' => 'role_a_' . $suffix]);
            $second = $service->bindStartedAccount($account, ['role_id' => 'role_b_' . $suffix]);

            $this->assertFalse($first['data']['rewarded']);
            $this->assertFalse($second['data']['rewarded']);
            $this->assertSame('role_a_' . $suffix, (string)Db::table('ga_users')->where('id', $inviteeId)->value('bound_role_id'));
            $this->assertSame('0.00', (string)Db::table('ga_users')->where('id', $inviterId)->value('balance'));
            $this->assertSame(0, Db::table('ga_user_point_transactions')->where('user_id', $inviterId)->where('type', 'invite_reward')->count());
        } finally {
            $connection->rollBack();
        }
    }

    public function testSameRoleIdCannotRewardAnotherUser(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$firstInviterId, $firstInviteeId, $suffix] = $this->createInvitePair('same_role_a');
            [$secondInviterId, $secondInviteeId] = $this->createInvitePair('same_role_b');
            $service = new ProfileService(new DbUserRepository(), new SystemSettingService());
            $roleId = 'shared_role_' . $suffix;

            $first = $service->bindStartedAccount([
                'id' => 99004,
                'user_id' => $firstInviteeId,
                'game_username' => 'first_' . $suffix,
            ], ['role_id' => $roleId]);

            try {
                $service->bindStartedAccount([
                    'id' => 99005,
                    'user_id' => $secondInviteeId,
                    'game_username' => 'second_' . $suffix,
                ], ['role_id' => $roleId]);
                $this->fail('Expected duplicate role_id to be rejected.');
            } catch (\app\exception\ApiException $exception) {
                $this->assertSame('该角色ID已被其他账号绑定', $exception->getMessage());
            }

            $this->assertFalse($first['data']['rewarded']);
            $this->assertSame('0.00', (string)Db::table('ga_users')->where('id', $firstInviterId)->value('balance'));
            $this->assertSame('0.00', (string)Db::table('ga_users')->where('id', $secondInviterId)->value('balance'));
        } finally {
            $connection->rollBack();
        }
    }

    public function testPreviouslyRewardedRoleIdCannotRewardAgainEvenWithoutCurrentBinding(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$inviterId, $inviteeId, $suffix] = $this->createInvitePair('rewarded_role');
            $roleId = 'rewarded_role_' . $suffix;
            $historicalInviteeId = Db::table('ga_users')->insertGetId([
                'account' => $suffix . '_historical_invitee',
                'email' => $suffix . '_historical_invitee@example.com',
                'nickname' => $suffix . '_historical_invitee',
                'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
                'invite_code' => strtoupper(substr(hash('crc32b', $suffix . '_historical'), 0, 8)),
                'invited_by_user_id' => $inviterId,
                'invite_registered_ip' => '10.1.2.4',
                'balance' => '0.00',
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::table('ga_user_point_transactions')->insert([
                'user_id' => $inviterId,
                'type' => 'invite_reward',
                'amount' => '1.00',
                'balance_after' => '1.00',
                'description' => 'historical reward',
                'related_user_id' => $historicalInviteeId,
                'related_role_id' => $roleId,
                'ip_address' => '',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $service = new ProfileService(new DbUserRepository(), new SystemSettingService());
            $result = $service->bindStartedAccount([
                'id' => 99006,
                'user_id' => $inviteeId,
                'game_username' => 'game_' . $suffix,
            ], ['role_id' => $roleId]);

            $this->assertFalse($result['data']['rewarded']);
            $this->assertSame('0.00', (string)Db::table('ga_users')->where('id', $inviterId)->value('balance'));
            $this->assertSame(1, Db::table('ga_user_point_transactions')->where('type', 'invite_reward')->where('related_role_id', $roleId)->count());
        } finally {
            $connection->rollBack();
        }
    }

    private function createInvitePair(string $prefix): array
    {
        $now = date('Y-m-d H:i:s');
        $suffix = $prefix . '_' . bin2hex(random_bytes(4));
        $inviterId = Db::table('ga_users')->insertGetId([
            'account' => $suffix . '_inviter',
            'email' => $suffix . '_inviter@example.com',
            'nickname' => $suffix . '_inviter',
            'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
            'invite_code' => strtoupper(substr(hash('crc32b', $suffix . '_a'), 0, 8)),
            'balance' => '0.00',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $inviteeId = Db::table('ga_users')->insertGetId([
            'account' => $suffix . '_invitee',
            'email' => $suffix . '_invitee@example.com',
            'nickname' => $suffix . '_invitee',
            'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
            'invite_code' => strtoupper(substr(hash('crc32b', $suffix . '_b'), 0, 8)),
            'invited_by_user_id' => $inviterId,
            'invite_registered_ip' => '10.1.2.3',
            'balance' => '0.00',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [(int)$inviterId, (int)$inviteeId, $suffix];
    }
}
