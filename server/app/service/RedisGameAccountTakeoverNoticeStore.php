<?php

namespace app\service;

use support\Redis;

class RedisGameAccountTakeoverNoticeStore implements GameAccountTakeoverNoticeStoreInterface
{
    private const PREFIX = 'gameassist:game_account_takeover_notices:';
    private const NOTICE_TTL_SECONDS = 600;
    private const MAX_NOTICES_PER_USER = 20;

    public function __construct(private mixed $redis = null)
    {
    }

    public function pushLoggedInElsewhere(int $userId, int $accountId): array
    {
        if ($userId <= 0 || $accountId <= 0) {
            throw new \InvalidArgumentException('Invalid takeover notice target');
        }

        $now = time();
        $notice = [
            'id' => $accountId . ':' . bin2hex(random_bytes(8)),
            'type' => 'game_account_logged_in_elsewhere',
            'account_id' => $accountId,
            'message_key' => 'client.home.notice.logged_in_elsewhere',
            'created_at' => $now,
            'expires_at' => $now + self::NOTICE_TTL_SECONDS,
        ];

        $key = $this->key($userId);
        $this->redis()->lPush($key, json_encode($notice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->redis()->lTrim($key, 0, self::MAX_NOTICES_PER_USER - 1);
        $this->redis()->expire($key, self::NOTICE_TTL_SECONDS);
        return $notice;
    }

    public function listForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $key = $this->key($userId);
        $rows = $this->redis()->lRange($key, 0, self::MAX_NOTICES_PER_USER - 1);
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $now = time();
        $notices = [];
        foreach ($rows as $row) {
            $notice = json_decode((string)$row, true);
            if (!is_array($notice) || (int)($notice['expires_at'] ?? 0) <= $now) {
                continue;
            }
            $notices[] = [
                'id' => (string)($notice['id'] ?? ''),
                'type' => (string)($notice['type'] ?? ''),
                'account_id' => (int)($notice['account_id'] ?? 0),
                'message_key' => (string)($notice['message_key'] ?? ''),
                'created_at' => (int)($notice['created_at'] ?? 0),
                'expires_at' => (int)($notice['expires_at'] ?? 0),
            ];
        }

        return array_values(array_filter(
            $notices,
            static fn (array $notice): bool => $notice['id'] !== ''
                && $notice['type'] === 'game_account_logged_in_elsewhere'
                && $notice['account_id'] > 0
                && $notice['message_key'] !== ''
        ));
    }

    private function key(int $userId): string
    {
        return self::PREFIX . 'users:' . $userId;
    }

    private function redis(): mixed
    {
        return $this->redis ??= Redis::connection('default');
    }
}

