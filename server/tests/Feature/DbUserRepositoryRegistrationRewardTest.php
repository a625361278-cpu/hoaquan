<?php

namespace tests\Feature;

use app\repository\DbUserRepository;
use PHPUnit\Framework\TestCase;
use support\Db;
use Throwable;

class DbUserRepositoryRegistrationRewardTest extends TestCase
{
    public function testCreatePersistsUserAndRegistrationRewardInOneTransaction(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $user = (new DbUserRepository())->create(
                'register_reward_' . $suffix,
                null,
                'register_reward_' . $suffix,
                password_hash('secret123', PASSWORD_DEFAULT),
                null,
                '10.9.8.7',
                strtoupper(substr($suffix, 0, 10)),
                'first_pet',
                password_hash('Mimi', PASSWORD_DEFAULT),
                3,
                '新用户注册赠送配额'
            );

            $transaction = Db::table('ga_user_point_transactions')
                ->where('user_id', $user['id'])
                ->where('type', 'registration_reward')
                ->first();
            $this->assertSame('3.00', (string)$user['balance']);
            $this->assertNotNull($transaction);
            $this->assertSame('3.00', (string)$transaction->amount);
            $this->assertSame('3.00', (string)$transaction->balance_after);
            $this->assertSame('10.9.8.7', (string)$transaction->ip_address);
        } finally {
            $connection->rollBack();
        }
    }

    public function testCreateWithZeroRewardDoesNotWriteTransaction(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $user = (new DbUserRepository())->create(
                'register_zero_' . $suffix,
                null,
                'register_zero_' . $suffix,
                password_hash('secret123', PASSWORD_DEFAULT),
                null,
                '',
                strtoupper(substr($suffix, 0, 10)),
                'first_pet',
                password_hash('Mimi', PASSWORD_DEFAULT),
                0,
                '新用户注册赠送配额'
            );

            $this->assertSame('0.00', (string)$user['balance']);
            $this->assertSame(0, Db::table('ga_user_point_transactions')->where('user_id', $user['id'])->count());
        } finally {
            $connection->rollBack();
        }
    }

    public function testRewardInsertFailureRollsBackCreatedUser(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $account = 'register_rollback_' . $suffix;

        try {
            (new DbUserRepository())->create(
                $account,
                null,
                $account,
                password_hash('secret123', PASSWORD_DEFAULT),
                null,
                '',
                strtoupper(substr($suffix, 0, 10)),
                'first_pet',
                password_hash('Mimi', PASSWORD_DEFAULT),
                1,
                str_repeat('x', 300)
            );
            $this->fail('Expected reward transaction insert to fail.');
        } catch (Throwable) {
            $this->assertFalse(Db::table('ga_users')->where('account', $account)->exists());
        } finally {
            $userId = Db::table('ga_users')->where('account', $account)->value('id');
            if ($userId !== null) {
                Db::table('ga_user_point_transactions')->where('user_id', $userId)->delete();
                Db::table('ga_users')->where('id', $userId)->delete();
            }
        }
    }
}
