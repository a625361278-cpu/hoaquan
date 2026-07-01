<?php

namespace app\repository;

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

    public function create(string $account, string $email, string $nickname, string $passwordHash): array
    {
        $now = date('Y-m-d H:i:s');
        $id = Db::table('ga_users')->insertGetId([
            'account' => $account,
            'email' => $email,
            'nickname' => $nickname,
            'password_hash' => $passwordHash,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $user = $this->findActiveById((int)$id);
        if (!$user) {
            throw new \RuntimeException('用户创建后读取失败');
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
