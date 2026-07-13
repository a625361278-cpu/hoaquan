<?php

namespace app\repository;

use app\service\SystemSettingService;
use support\Db;

class DbUserRepository implements UserRepositoryInterface
{
    public function findActiveByAccount(string $account): ?array
    {
        $row = Db::table('ga_users')
            ->where('account', $account)
            ->where('status', 1)
            ->first();

        return $row ? (array)$row : null;
    }

    public function findActiveByAccountAndEmail(string $account, string $email): ?array
    {
        $row = Db::table('ga_users')
            ->where('account', $account)
            ->where('email', $email)
            ->where('status', 1)
            ->first();

        return $row ? (array)$row : null;
    }

    public function findActiveById(int $id): ?array
    {
        $row = Db::table('ga_users')
            ->where('id', $id)
            ->where('status', 1)
            ->first();

        return $row ? (array)$row : null;
    }

    public function accountExists(string $account): bool
    {
        return Db::table('ga_users')->where('account', $account)->exists();
    }

    public function emailExists(string $email): bool
    {
        return Db::table('ga_users')->where('email', $email)->exists();
    }

    public function findByInviteCode(string $inviteCode): ?array
    {
        $row = Db::table('ga_users')
            ->where('invite_code', $inviteCode)
            ->where('status', 1)
            ->first();

        return $row ? (array)$row : null;
    }

    public function inviteCodeExists(string $inviteCode): bool
    {
        return Db::table('ga_users')->where('invite_code', $inviteCode)->exists();
    }

    public function create(
        string $account,
        ?string $email,
        string $nickname,
        string $passwordHash,
        ?int $invitedByUserId = null,
        string $inviteRegisteredIp = '',
        ?string $inviteCode = null,
        ?string $securityQuestionKey = null,
        ?string $securityAnswerHash = null,
        int $registrationRewardPoints = 0,
        string $registrationRewardDescription = ''
    ): array {
        if ($registrationRewardPoints < 0 || $registrationRewardPoints > SystemSettingService::MAX_REGISTRATION_REWARD_POINTS) {
            throw new \InvalidArgumentException('Registration reward points are outside the allowed range');
        }

        return Db::transaction(function () use ($account, $email, $nickname, $passwordHash, $invitedByUserId, $inviteRegisteredIp, $inviteCode, $securityQuestionKey, $securityAnswerHash, $registrationRewardPoints, $registrationRewardDescription): array {
            $now = date('Y-m-d H:i:s');
            $balance = number_format($registrationRewardPoints, 2, '.', '');
            $id = Db::table('ga_users')->insertGetId([
                'account' => $account,
                'email' => $email === '' ? null : $email,
                'nickname' => $nickname,
                'password_hash' => $passwordHash,
                'balance' => $balance,
                'security_question_key' => $securityQuestionKey,
                'security_answer_hash' => $securityAnswerHash,
                'invite_code' => $inviteCode,
                'invited_by_user_id' => $invitedByUserId,
                'invite_registered_ip' => $inviteRegisteredIp,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($registrationRewardPoints > 0) {
                Db::table('ga_user_point_transactions')->insert([
                    'user_id' => $id,
                    'type' => 'registration_reward',
                    'amount' => $balance,
                    'balance_after' => $balance,
                    'description' => $registrationRewardDescription,
                    'related_user_id' => null,
                    'related_role_id' => '',
                    'related_payment_order_id' => null,
                    'ip_address' => $inviteRegisteredIp,
                    'created_at' => $now,
                ]);
            }

            $user = $this->findActiveById((int)$id);
            if (!$user) {
                throw new \RuntimeException('用户创建后读取失败');
            }
            return $user;
        });
    }

    public function updateInviteCode(int $id, string $inviteCode): array
    {
        $updated = Db::table('ga_users')
            ->where('id', $id)
            ->where('status', 1)
            ->whereNull('invite_code')
            ->update([
                'invite_code' => $inviteCode,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if ($updated !== 1) {
            $user = $this->findActiveById($id);
            if ($user && ($user['invite_code'] ?? '') === $inviteCode) {
                return $user;
            }
            throw new \RuntimeException('邀请码更新失败，用户状态异常');
        }

        $user = $this->findActiveById($id);
        if (!$user) {
            throw new \RuntimeException('邀请码更新后读取用户失败');
        }
        return $user;
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $updated = Db::table('ga_users')
            ->where('id', $id)
            ->where('status', 1)
            ->update([
                'password_hash' => $passwordHash,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if ($updated !== 1) {
            throw new \RuntimeException('密码更新失败，用户状态异常');
        }
    }
}
