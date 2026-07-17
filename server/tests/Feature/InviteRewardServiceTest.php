<?php

namespace tests\Feature;

use app\service\InviteRewardService;
use app\service\SystemSettingService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Db;

class InviteRewardServiceTest extends TestCase
{
    public function testOnlyIntegerLevelsAtOrAboveThresholdReward(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            foreach ([[29, false], [30, true], [31, true], ['30', true], [30.0, true]] as [$level, $expectedRewarded]) {
                $context = $this->createEligibleContext('level_' . str_replace('.', '_', (string)$level));
                $result = $this->service()->tryGrantForAccountLevel($context['account_id'], $level);

                $this->assertSame($expectedRewarded, $result['rewarded'], 'Unexpected result for level ' . var_export($level, true));
                $this->assertSame($expectedRewarded ? '1.00' : '0.00', (string)Db::table('ga_users')->where('id', $context['inviter_id'])->value('balance'));
            }
        } finally {
            $connection->rollBack();
        }
    }

    public function testMissingAndMalformedLevelsNeverReward(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $values = [null, '', -1, 30.5, '30.5', 'level-30', true, [], str_repeat('9', 40)];
            foreach ($values as $index => $value) {
                $context = $this->createEligibleContext('invalid_' . $index);
                $result = $this->service()->tryGrantForAccountLevel($context['account_id'], $value);

                $this->assertFalse($result['rewarded']);
                $this->assertContains($result['reason'], ['level_missing', 'level_invalid']);
                $this->assertSame('0.00', (string)Db::table('ga_users')->where('id', $context['inviter_id'])->value('balance'));
            }
        } finally {
            $connection->rollBack();
        }
    }

    public function testRunningStartedAccountAndExactBoundRoleAreRequired(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $notRunning = $this->createEligibleContext('not_running', ['status' => 'starting']);
            $notDesired = $this->createEligibleContext('not_desired', ['desired_running' => 0]);
            $roleMismatch = $this->createEligibleContext('role_mismatch', ['third_party_account_id' => 'different_role']);

            $this->assertSame('account_not_running', $this->service()->tryGrantForAccountLevel($notRunning['account_id'], 30)['reason']);
            $this->assertSame('account_not_running', $this->service()->tryGrantForAccountLevel($notDesired['account_id'], 30)['reason']);
            $this->assertSame('bound_role_mismatch', $this->service()->tryGrantForAccountLevel($roleMismatch['account_id'], 30)['reason']);

            foreach ([$notRunning, $notDesired, $roleMismatch] as $context) {
                $this->assertSame('0.00', (string)Db::table('ga_users')->where('id', $context['inviter_id'])->value('balance'));
            }
        } finally {
            $connection->rollBack();
        }
    }

    public function testAnotherGameAccountsLevelCannotRewardTheBoundRole(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $context = $this->createEligibleContext('other_account');
            $otherAccountId = (int)Db::table('ga_game_accounts')->insertGetId($this->accountRow(
                $context['invitee_id'],
                'other_role_' . $context['suffix'],
                'other_account_' . $context['suffix']
            ));

            $result = $this->service()->tryGrantForAccountLevel($otherAccountId, 99);

            $this->assertFalse($result['rewarded']);
            $this->assertSame('bound_role_mismatch', $result['reason']);
            $this->assertSame('0.00', (string)Db::table('ga_users')->where('id', $context['inviter_id'])->value('balance'));
        } finally {
            $connection->rollBack();
        }
    }

    public function testRepeatedEligibleReportsOnlyCreditOnce(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $context = $this->createEligibleContext('repeat');
            $first = $this->service()->tryGrantForAccountLevel($context['account_id'], 30);
            $second = $this->service()->tryGrantForAccountLevel($context['account_id'], 88);

            $this->assertTrue($first['rewarded']);
            $this->assertFalse($second['rewarded']);
            $this->assertSame('already_rewarded', $second['reason']);
            $this->assertSame('1.00', (string)Db::table('ga_users')->where('id', $context['inviter_id'])->value('balance'));
            $this->assertSame(1, Db::table('ga_user_point_transactions')
                ->where('type', 'invite_reward')
                ->where('related_user_id', $context['invitee_id'])
                ->count());
        } finally {
            $connection->rollBack();
        }
    }

    public function testInvitationAndActiveInviterAreRequired(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $missingInvitation = $this->createEligibleContext('missing_invitation');
            Db::table('ga_users')->where('id', $missingInvitation['invitee_id'])->update(['invited_by_user_id' => null]);

            $inactiveInviter = $this->createEligibleContext('inactive_inviter');
            Db::table('ga_users')->where('id', $inactiveInviter['inviter_id'])->update(['status' => 0]);

            $selfInvitation = $this->createEligibleContext('self_invitation');
            Db::table('ga_users')->where('id', $selfInvitation['invitee_id'])->update(['invited_by_user_id' => $selfInvitation['invitee_id']]);

            $this->assertSame('invitation_missing', $this->service()->tryGrantForAccountLevel($missingInvitation['account_id'], 30)['reason']);
            $this->assertSame('inviter_inactive', $this->service()->tryGrantForAccountLevel($inactiveInviter['account_id'], 30)['reason']);
            $this->assertSame('self_invitation', $this->service()->tryGrantForAccountLevel($selfInvitation['account_id'], 30)['reason']);
            $this->assertSame(0, Db::table('ga_user_point_transactions')
                ->where('type', 'invite_reward')
                ->whereIn('related_user_id', [
                    $missingInvitation['invitee_id'],
                    $inactiveInviter['invitee_id'],
                    $selfInvitation['invitee_id'],
                ])
                ->count());
        } finally {
            $connection->rollBack();
        }
    }

    public function testRoleWithHistoricalRewardCannotRewardAgain(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $context = $this->createEligibleContext('historical_role');
            $historicalInviteeId = $this->createUser('historical_role_user_' . $context['suffix']);
            Db::table('ga_user_point_transactions')->insert([
                'user_id' => $context['inviter_id'],
                'type' => 'invite_reward',
                'amount' => '1.00',
                'balance_after' => '1.00',
                'description' => 'historical role reward',
                'related_user_id' => $historicalInviteeId,
                'related_role_id' => $context['role_id'],
                'ip_address' => '',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $result = $this->service()->tryGrantForAccountLevel($context['account_id'], 30);

            $this->assertFalse($result['rewarded']);
            $this->assertSame('role_already_rewarded', $result['reason']);
            $this->assertSame('0.00', (string)Db::table('ga_users')->where('id', $context['inviter_id'])->value('balance'));
        } finally {
            $connection->rollBack();
        }
    }

    public function testLegacyDailyAndSameIpLimitsAreIgnored(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $context = $this->createEligibleContext('legacy_limits');
            Db::table('ga_system_settings')->updateOrInsert(['name' => 'invite_daily_limit'], ['value' => '1', 'remark' => 'legacy']);
            Db::table('ga_system_settings')->updateOrInsert(['name' => 'invite_same_ip_daily_limit'], ['value' => '1', 'remark' => 'legacy']);

            $historicalInviteeId = $this->createUser('historical_' . $context['suffix'], [
                'invited_by_user_id' => $context['inviter_id'],
                'invite_registered_ip' => '10.0.0.8',
                'invite_rewarded_at' => date('Y-m-d H:i:s'),
            ]);
            Db::table('ga_user_point_transactions')->insert([
                'user_id' => $context['inviter_id'],
                'type' => 'invite_reward',
                'amount' => '1.00',
                'balance_after' => '1.00',
                'description' => 'historical daily reward',
                'related_user_id' => $historicalInviteeId,
                'related_role_id' => 'historical_role_' . $context['suffix'],
                'ip_address' => '10.0.0.8',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $result = $this->service()->tryGrantForAccountLevel($context['account_id'], 30);

            $this->assertTrue($result['rewarded']);
            $this->assertSame('1.00', (string)Db::table('ga_users')->where('id', $context['inviter_id'])->value('balance'));
        } finally {
            $connection->rollBack();
        }
    }

    public function testEligibleStatusAfterBindingRewardsHistoricalUnrewardedUser(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $context = $this->createEligibleContext('historical_bound');
            $this->assertNull(Db::table('ga_users')->where('id', $context['invitee_id'])->value('invite_rewarded_at'));

            $result = $this->service()->tryGrantForAccountLevel($context['account_id'], '45');

            $this->assertTrue($result['rewarded']);
            $this->assertNotNull(Db::table('ga_users')->where('id', $context['invitee_id'])->value('invite_rewarded_at'));
        } finally {
            $connection->rollBack();
        }
    }

    public function testStatusAndStartedArrivalOrderBothEventuallyRewardOnce(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $statusFirst = $this->createEligibleContext('status_first', ['status' => 'starting']);
            $beforeStarted = $this->service()->tryGrantForAccountLevel($statusFirst['account_id'], 30);
            $this->assertFalse($beforeStarted['rewarded']);
            Db::table('ga_game_accounts')->where('id', $statusFirst['account_id'])->update(['status' => 'running']);
            $afterStarted = $this->service()->tryGrantForAccountLevel($statusFirst['account_id'], 30);
            $this->assertTrue($afterStarted['rewarded']);

            $startedFirst = $this->createEligibleContext('started_first');
            $withoutLevel = $this->service()->tryGrantForAccountLevel($startedFirst['account_id'], null);
            $this->assertFalse($withoutLevel['rewarded']);
            $afterStatus = $this->service()->tryGrantForAccountLevel($startedFirst['account_id'], 30);
            $this->assertTrue($afterStatus['rewarded']);

            $this->assertSame(1, Db::table('ga_user_point_transactions')->where('related_user_id', $statusFirst['invitee_id'])->where('type', 'invite_reward')->count());
            $this->assertSame(1, Db::table('ga_user_point_transactions')->where('related_user_id', $startedFirst['invitee_id'])->where('type', 'invite_reward')->count());
        } finally {
            $connection->rollBack();
        }
    }

    public function testExistingRewardWithoutRewardMarkerIsExposedAsInconsistentState(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $context = $this->createEligibleContext('inconsistent');
            Db::table('ga_user_point_transactions')->insert([
                'user_id' => $context['inviter_id'],
                'type' => 'invite_reward',
                'amount' => '1.00',
                'balance_after' => '1.00',
                'description' => 'existing reward',
                'related_user_id' => $context['invitee_id'],
                'related_role_id' => $context['role_id'],
                'ip_address' => '10.0.0.8',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('邀请奖励状态不一致');
            $this->service()->tryGrantForAccountLevel($context['account_id'], 30);
        } finally {
            $connection->rollBack();
        }
    }

    private function service(int $minimumLevel = 30): InviteRewardService
    {
        $settings = new class($minimumLevel) extends SystemSettingService {
            public function __construct(private int $minimumLevel)
            {
            }

            public function inviteRewardMinRoleLevel(): int
            {
                return $this->minimumLevel;
            }
        };
        return new InviteRewardService($settings);
    }

    private function createEligibleContext(string $prefix, array $accountOverrides = []): array
    {
        $suffix = $prefix . '_' . bin2hex(random_bytes(4));
        $inviterId = $this->createUser('inviter_' . $suffix);
        $roleId = 'role_' . $suffix;
        $inviteeId = $this->createUser('invitee_' . $suffix, [
            'invited_by_user_id' => $inviterId,
            'invite_registered_ip' => '10.0.0.8',
            'bound_role_id' => $roleId,
            'role_bound_at' => date('Y-m-d H:i:s'),
        ]);
        $accountId = (int)Db::table('ga_game_accounts')->insertGetId(array_merge(
            $this->accountRow($inviteeId, $roleId, 'account_' . $suffix),
            $accountOverrides
        ));

        return [
            'suffix' => $suffix,
            'inviter_id' => $inviterId,
            'invitee_id' => $inviteeId,
            'account_id' => $accountId,
            'role_id' => $roleId,
        ];
    }

    private function createUser(string $suffix, array $overrides = []): int
    {
        $now = date('Y-m-d H:i:s');
        return (int)Db::table('ga_users')->insertGetId(array_merge([
            'account' => $suffix,
            'email' => $suffix . '@example.com',
            'nickname' => $suffix,
            'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
            'invite_code' => strtoupper(substr(hash('sha256', $suffix), 0, 8)),
            'balance' => '0.00',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function accountRow(int $userId, string $roleId, string $displayName): array
    {
        $now = date('Y-m-d H:i:s');
        return [
            'user_id' => $userId,
            'display_name' => $displayName,
            'login_method' => 1,
            'game_username' => $displayName . '@example.com',
            'game_password_cipher' => '',
            'game_uid' => '',
            'game_token_cipher' => null,
            'channel_code' => 'official_app',
            'server_id' => '',
            'server_name' => '',
            'status' => 'running',
            'sync_status' => 'synced',
            'third_party_account_id' => $roleId,
            'log_session_id' => 'session-' . $displayName,
            'desired_running' => 1,
            'auto_restart_attempts' => 0,
            'auto_restart_next_at' => null,
            'auto_restart_last_error' => '',
            'expire_time' => '2099-01-01 00:00:00',
            'remark' => '',
            'config_json' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
