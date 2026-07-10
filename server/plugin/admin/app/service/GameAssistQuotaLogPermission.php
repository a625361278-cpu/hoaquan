<?php

namespace plugin\admin\app\service;

use plugin\admin\app\model\Role;
use plugin\admin\app\model\Rule;
use RuntimeException;

class GameAssistQuotaLogPermission
{
    public const USER_RULE_KEY = 'plugin\\admin\\app\\controller\\GameAssistUserController';
    public const QUOTA_LOG_RULE_KEY = self::USER_RULE_KEY . '@quotaLogs';

    public static function normalizeRuleString(string $rules): string
    {
        if ($rules === '' || $rules === '*') {
            return $rules;
        }

        [$userRuleId, $quotaLogRuleId] = self::ruleIds();
        $ruleIds = array_values(array_unique(array_filter(explode(',', $rules), static fn ($id): bool => $id !== '')));
        $hasUserRule = in_array((string)$userRuleId, $ruleIds, true);
        $ruleIds = array_values(array_filter($ruleIds, static fn ($id): bool => $id !== (string)$quotaLogRuleId));
        if ($hasUserRule) {
            $ruleIds[] = (string)$quotaLogRuleId;
        }

        return implode(',', array_values(array_unique($ruleIds)));
    }

    public static function syncExistingRoles(): int
    {
        $updated = 0;
        foreach (Role::where('rules', '<>', '*')->get() as $role) {
            $normalized = self::normalizeRuleString((string)$role->rules);
            if ($normalized === (string)$role->rules) {
                continue;
            }
            $role->rules = $normalized;
            $role->save();
            $updated++;
        }
        return $updated;
    }

    private static function ruleIds(): array
    {
        $rules = Rule::whereIn('key', [self::USER_RULE_KEY, self::QUOTA_LOG_RULE_KEY])
            ->pluck('id', 'key')
            ->toArray();
        if (!isset($rules[self::USER_RULE_KEY], $rules[self::QUOTA_LOG_RULE_KEY])) {
            throw new RuntimeException('GameAssist用户或配额日志菜单权限尚未同步');
        }
        return [(int)$rules[self::USER_RULE_KEY], (int)$rules[self::QUOTA_LOG_RULE_KEY]];
    }
}
