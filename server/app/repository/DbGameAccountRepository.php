<?php

namespace app\repository;

use support\Db;

class DbGameAccountRepository implements GameAccountRepositoryInterface
{
    public function listByUserId(int $userId): array
    {
        return Db::table('ga_game_accounts')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get()
            ->map(static fn ($row): array => (array)$row)
            ->all();
    }
}
