<?php

namespace app\service;

interface GameAccountTakeoverNoticeStoreInterface
{
    public function pushLoggedInElsewhere(int $userId, int $accountId): array;

    public function listForUser(int $userId): array;
}

