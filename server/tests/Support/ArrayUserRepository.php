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
        foreach ($this->users as $user) {
            if (($user['email'] ?? null) === $email) {
                return true;
            }
        }
        return false;
    }

    public function create(string $account, string $email, string $nickname, string $passwordHash): array
    {
        $user = [
            'id' => $this->nextId++,
            'account' => $account,
            'email' => $email,
            'nickname' => $nickname,
            'password_hash' => $passwordHash,
            'avatar' => '',
            'balance' => '0.00',
            'expire_at' => null,
            'status' => 1,
        ];
        $this->users[] = $user;
        return $user;
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
