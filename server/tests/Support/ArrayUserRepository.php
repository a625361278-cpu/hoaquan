<?php

namespace tests\Support;

use app\repository\UserRepositoryInterface;

class ArrayUserRepository implements UserRepositoryInterface
{
    private int $nextId = 100;

    public function __construct(private array $users)
    {
    }

    public function findActiveByAccount(string $account): ?array
    {
        foreach ($this->users as $user) {
            if ($user['account'] === $account && $user['status'] === 1) {
                return $user;
            }
        }
        return null;
    }

    public function findActiveByAccountAndEmail(string $account, string $email): ?array
    {
        foreach ($this->users as $user) {
            if ($user['account'] === $account && ($user['email'] ?? '') === $email && $user['status'] === 1) {
                return $user;
            }
        }
        return null;
    }

    public function findActiveById(int $id): ?array
    {
        foreach ($this->users as $user) {
            if ((int)$user['id'] === $id && $user['status'] === 1) {
                return $user;
            }
        }
        return null;
    }

    public function accountExists(string $account): bool
    {
        foreach ($this->users as $user) {
            if ($user['account'] === $account) {
                return true;
            }
        }
        return false;
    }

    public function emailExists(string $email): bool
    {
        if ($email === '') {
            return false;
        }
        foreach ($this->users as $user) {
            if (($user['email'] ?? null) !== null && ($user['email'] ?? '') === $email) {
                return true;
            }
        }
        return false;
    }

    public function findByInviteCode(string $inviteCode): ?array
    {
        foreach ($this->users as $user) {
            if (($user['invite_code'] ?? null) === $inviteCode && $user['status'] === 1) {
                return $user;
            }
        }
        return null;
    }

    public function inviteCodeExists(string $inviteCode): bool
    {
        foreach ($this->users as $user) {
            if (($user['invite_code'] ?? null) === $inviteCode) {
                return true;
            }
        }
        return false;
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
        ?string $securityAnswerHash = null
    ): array {
        $user = [
            'id' => $this->nextId++,
            'account' => $account,
            'email' => $email ?? '',
            'nickname' => $nickname,
            'password_hash' => $passwordHash,
            'avatar' => '',
            'balance' => '0.00',
            'expire_at' => null,
            'security_question_key' => $securityQuestionKey,
            'security_answer_hash' => $securityAnswerHash,
            'invite_code' => $inviteCode,
            'invited_by_user_id' => $invitedByUserId,
            'invite_registered_ip' => $inviteRegisteredIp,
            'bound_role_id' => null,
            'role_bound_at' => null,
            'status' => 1,
        ];
        $this->users[] = $user;
        return $user;
    }

    public function updateInviteCode(int $id, string $inviteCode): array
    {
        foreach ($this->users as &$user) {
            if ((int)$user['id'] === $id && $user['status'] === 1) {
                $user['invite_code'] = $inviteCode;
                return $user;
            }
        }

        throw new \RuntimeException('邀请码更新失败，用户状态异常');
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        foreach ($this->users as &$user) {
            if ((int)$user['id'] === $id && $user['status'] === 1) {
                $user['password_hash'] = $passwordHash;
                return;
            }
        }

        throw new \RuntimeException('密码更新失败，用户状态异常');
    }
}
