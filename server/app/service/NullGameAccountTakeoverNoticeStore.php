<?php

namespace app\service;

class NullGameAccountTakeoverNoticeStore implements GameAccountTakeoverNoticeStoreInterface
{
    public function pushLoggedInElsewhere(int $userId, int $accountId): array
    {
        return [];
    }

    public function listForUser(int $userId): array
    {
        return [];
    }
}

