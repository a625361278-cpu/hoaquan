<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;
use plugin\webman\gateway\Events;
use ReflectionMethod;
use support\Db;

class ThirdPartyStartedMessageTest extends TestCase
{
    public function testStartedWithMatchingContextStoresRoleIdAndMarksRunning(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$userId, $accountId] = $this->createAccount([
                'status' => 'starting',
                'desired_running' => 1,
                'log_session_id' => 'session-1',
                'expire_time' => '2099-01-01 00:00:00',
            ]);

            $this->markStarted($accountId, [
                'type' => 'started',
                'request_id' => 'request-1',
                'session_id' => 'session-1',
                'role_id' => 'role-123',
                'display_name' => 'Role Name',
            ], [
                'request_id' => 'request-1',
                'session_id' => 'session-1',
            ]);

            $account = (array)Db::table('ga_game_accounts')->where('id', $accountId)->first();
            $this->assertSame($userId, (int)$account['user_id']);
            $this->assertSame('running', $account['status']);
            $this->assertSame('Role Name', $account['display_name']);
            $this->assertSame('role-123', $account['third_party_account_id']);
        } finally {
            $connection->rollBack();
        }
    }

    public function testStartedWithMismatchedRequestOrSessionIsIgnored(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [, $accountId] = $this->createAccount([
                'status' => 'starting',
                'desired_running' => 1,
                'log_session_id' => 'session-1',
                'expire_time' => '2099-01-01 00:00:00',
            ]);

            $this->markStarted($accountId, [
                'type' => 'started',
                'request_id' => 'old-request',
                'session_id' => 'session-1',
                'role_id' => 'role-123',
            ], [
                'request_id' => 'request-1',
                'session_id' => 'session-1',
            ]);

            $account = (array)Db::table('ga_game_accounts')->where('id', $accountId)->first();
            $this->assertSame('starting', $account['status']);
            $this->assertSame('', $account['third_party_account_id']);
        } finally {
            $connection->rollBack();
        }
    }

    public function testSocialStartedWithoutRoleIdFallsBackToGameUid(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [, $accountId] = $this->createAccount([
                'login_method' => 2,
                'game_username' => '',
                'game_password_cipher' => null,
                'game_uid' => 'facebook-uid-1001',
                'game_token_cipher' => 'encrypted-token',
                'status' => 'starting',
                'desired_running' => 1,
                'log_session_id' => 'session-social',
                'expire_time' => '2099-01-01 00:00:00',
            ]);

            $this->markStarted($accountId, [
                'type' => 'started',
                'request_id' => 'request-social',
                'session_id' => 'session-social',
            ], [
                'request_id' => 'request-social',
                'session_id' => 'session-social',
            ]);

            $account = (array)Db::table('ga_game_accounts')->where('id', $accountId)->first();
            $this->assertSame('running', $account['status']);
            $this->assertSame('facebook-uid-1001', $account['third_party_account_id']);
        } finally {
            $connection->rollBack();
        }
    }

    public function testLateStartedAfterManualStopIsIgnored(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [, $accountId] = $this->createAccount([
                'status' => 'stopped',
                'desired_running' => 0,
                'log_session_id' => '',
                'expire_time' => '2099-01-01 00:00:00',
            ]);

            $this->markStarted($accountId, [
                'type' => 'started',
                'request_id' => 'request-1',
                'session_id' => 'session-1',
                'role_id' => 'role-123',
            ], [
                'request_id' => 'request-1',
                'session_id' => 'session-1',
            ]);

            $account = (array)Db::table('ga_game_accounts')->where('id', $accountId)->first();
            $this->assertSame('stopped', $account['status']);
            $this->assertSame('', $account['third_party_account_id']);
        } finally {
            $connection->rollBack();
        }
    }

    public function testLateStartedAfterQuotaExpiryIsIgnored(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [, $accountId] = $this->createAccount([
                'status' => 'starting',
                'desired_running' => 1,
                'log_session_id' => 'session-1',
                'expire_time' => '2000-01-01 00:00:00',
            ]);

            $this->markStarted($accountId, [
                'type' => 'started',
                'request_id' => 'request-1',
                'session_id' => 'session-1',
                'role_id' => 'role-123',
            ], [
                'request_id' => 'request-1',
                'session_id' => 'session-1',
            ]);

            $account = (array)Db::table('ga_game_accounts')->where('id', $accountId)->first();
            $this->assertSame('starting', $account['status']);
            $this->assertSame('', $account['third_party_account_id']);
        } finally {
            $connection->rollBack();
        }
    }

    private function markStarted(int $accountId, array $payload, array $state): void
    {
        $method = new ReflectionMethod(Events::class, 'markStarted');
        $method->setAccessible(true);
        $method->invoke(null, $accountId, $payload, $state);
    }

    private function createAccount(array $overrides): array
    {
        $now = date('Y-m-d H:i:s');
        $suffix = bin2hex(random_bytes(4));
        $userId = (int)Db::table('ga_users')->insertGetId([
            'account' => 'started_user_' . $suffix,
            'email' => 'started_user_' . $suffix . '@example.com',
            'nickname' => 'started_user_' . $suffix,
            'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
            'invite_code' => strtoupper(substr(hash('crc32b', $suffix), 0, 8)),
            'balance' => '0.00',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $accountId = (int)Db::table('ga_game_accounts')->insertGetId(array_merge([
            'user_id' => $userId,
            'display_name' => 'player_' . $suffix,
            'login_method' => 1,
            'game_username' => 'player_' . $suffix . '@example.com',
            'game_password_cipher' => '',
            'game_uid' => '',
            'game_token_cipher' => null,
            'channel_code' => 'official_app',
            'server_id' => '',
            'server_name' => '',
            'status' => 'starting',
            'sync_status' => 'local_unsynced',
            'third_party_account_id' => '',
            'log_session_id' => 'session-1',
            'desired_running' => 1,
            'auto_restart_attempts' => 0,
            'auto_restart_next_at' => null,
            'auto_restart_last_error' => '',
            'expire_time' => '2099-01-01 00:00:00',
            'remark' => '',
            'config_json' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return [$userId, $accountId];
    }
}
