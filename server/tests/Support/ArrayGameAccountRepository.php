<?php

namespace tests\Support;

use app\repository\GameAccountRepositoryInterface;

class ArrayGameAccountRepository implements GameAccountRepositoryInterface
{
    public function __construct(private array $accounts)
    {
    }

    public function listByUserId(int $userId): array
    {
        return array_values(array_filter(
            $this->accounts,
            static fn (array $account): bool => (int)$account['user_id'] === $userId
        ));
    }
}
