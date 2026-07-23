<?php

namespace plugin\admin\app\service;

class GameAssistQuotaLogPermission
{
    public const USER_RULE_KEY = 'plugin\\admin\\app\\controller\\GameAssistUserController';
    public const QUOTA_LOG_RULE_KEY = self::USER_RULE_KEY . '@quotaLogs';

    public static function normalizeRuleString(string $rules): string
    {
        return $rules;
    }

    public static function syncExistingRoles(): int
    {
        return 0;
    }
}
