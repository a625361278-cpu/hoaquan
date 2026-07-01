<?php

namespace app\service;

use support\Redis;

class RedisTokenStore implements TokenStoreInterface
{
    private const PREFIX = 'gameassist:user_token:';
    private const TTL = 604800;

    public function create(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        Redis::setEx(self::PREFIX . $token, self::TTL, (string)$userId);
        return $token;
    }

    public function getUserId(string $token): ?int
    {
        if ($token === '') {
            return null;
        }
        $userId = Redis::get(self::PREFIX . $token);
        return $userId === false || $userId === null ? null : (int)$userId;
    }

    public function delete(string $token): void
    {
        if ($token !== '') {
            Redis::del(self::PREFIX . $token);
        }
    }
}
