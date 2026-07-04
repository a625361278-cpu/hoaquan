<?php

namespace tests\Feature;

use app\repository\DbUserRepository;
use app\service\ProfileService;
use app\service\SystemSettingService;
use PHPUnit\Framework\TestCase;
use support\Db;

class ProfileServiceInviteTest extends TestCase
{
    public function testStartedAccountOnlyRewardsInvitationOnceForSameUser(): void
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
                'third_party_account_id' => 'role_once_' . $suffix,
                'display_name' => 'role-name',
            ];

            $first = $service->bindStartedAccount($account, $payload);
            $second = $service->bindStartedAccount($account, $payload);

            $this->assertTrue($first['data']['rewarded']);
            $this->assertFalse($second['data']['rewarded']);
            $this->assertSame('1.00', (string)Db::table('ga_users')->where('id', $inviterId)->value('balance'));
            $this->assertSame(1, Db::table('ga_user_point_transactions')->where('user_id', $inviterId)->where('type', 'invite_reward')->count());
        } finally {
            $connection->rollBack();
        }
    }
}
