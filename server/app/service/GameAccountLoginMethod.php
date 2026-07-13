<?php

namespace app\service;

final class GameAccountLoginMethod
{
    public const ACCOUNT_PASSWORD = 1;
    public const FACEBOOK = 2;
    public const GOOGLE = 3;
    public const ALL = [self::ACCOUNT_PASSWORD, self::FACEBOOK, self::GOOGLE];

    public static function isSupported(int $method): bool
    {
        return in_array($method, self::ALL, true);
    }

    public static function isSocial(int $method): bool
    {
        return in_array($method, [self::FACEBOOK, self::GOOGLE], true);
    }

    private function __construct()
    {
    }
}
