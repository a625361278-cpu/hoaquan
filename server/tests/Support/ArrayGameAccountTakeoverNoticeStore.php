<?php

namespace tests\Support;

use app\service\GameAccountTakeoverNoticeStoreInterface;

class ArrayGameAccountTakeoverNoticeStore implements GameAccountTakeoverNoticeStoreInterface
{
    public array $notices = [];

    public function pushLoggedInElsewhere(int $userId, int $accountId): array
    {
        $notice = [
            'id' => $accountId . ':notice-' . (count($this->notices) + 1),
            'type' => 'game_account_logged_in_elsewhere',
            'account_id' => $accountId,
            'user_id' => $userId,
            'message_key' => 'client.home.notice.logged_in_elsewhere',
            'created_at' => 1783123200,
            'expires_at' => 1783123800,
        ];
        $this->notices[] = $notice;
        return $notice;
    }

    public function listForUser(int $userId): array
    {
        return array_values(array_filter(
            $this->notices,
            static fn (array $notice): bool => (int)($notice['user_id'] ?? 0) === $userId
        ));
    }
}

