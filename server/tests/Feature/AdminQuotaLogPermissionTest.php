<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;
use plugin\admin\app\service\GameAssistQuotaLogPermission;
use support\Db;

class AdminQuotaLogPermissionTest extends TestCase
{
    public function testQuotaLogPermissionFollowsGameAssistUserPermission(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$userRuleId, $quotaLogRuleId] = $this->ensureRules();

            $withUser = GameAssistQuotaLogPermission::normalizeRuleString('999999,' . $userRuleId);
            $withoutUser = GameAssistQuotaLogPermission::normalizeRuleString('999999,' . $quotaLogRuleId);

            $this->assertContains((string)$quotaLogRuleId, explode(',', $withUser));
            $this->assertNotContains((string)$quotaLogRuleId, explode(',', $withoutUser));
            $this->assertSame('*', GameAssistQuotaLogPermission::normalizeRuleString('*'));
        } finally {
            $connection->rollBack();
        }
    }

    public function testSyncExistingRolesAddsAndRemovesDerivedPermission(): void
    {
        $connection = Db::connection();
        $connection->beginTransaction();

        try {
            [$userRuleId, $quotaLogRuleId] = $this->ensureRules();
            $now = date('Y-m-d H:i:s');
            $withUserRoleId = (int)Db::table('wa_roles')->insertGetId([
                'name' => 'quota-with-user-' . bin2hex(random_bytes(3)),
                'rules' => (string)$userRuleId,
                'created_at' => $now,
                'updated_at' => $now,
                'pid' => 1,
            ]);
            $withoutUserRoleId = (int)Db::table('wa_roles')->insertGetId([
                'name' => 'quota-without-user-' . bin2hex(random_bytes(3)),
                'rules' => (string)$quotaLogRuleId,
                'created_at' => $now,
                'updated_at' => $now,
                'pid' => 1,
            ]);

            GameAssistQuotaLogPermission::syncExistingRoles();

            $withUserRules = explode(',', (string)Db::table('wa_roles')->where('id', $withUserRoleId)->value('rules'));
            $withoutUserRules = explode(',', (string)Db::table('wa_roles')->where('id', $withoutUserRoleId)->value('rules'));
            $this->assertContains((string)$quotaLogRuleId, $withUserRules);
            $this->assertNotContains((string)$quotaLogRuleId, $withoutUserRules);
        } finally {
            $connection->rollBack();
        }
    }

    private function ensureRules(): array
    {
        $userRuleId = Db::table('wa_rules')->where('key', GameAssistQuotaLogPermission::USER_RULE_KEY)->value('id');
        $quotaLogRuleId = Db::table('wa_rules')->where('key', GameAssistQuotaLogPermission::QUOTA_LOG_RULE_KEY)->value('id');
        $now = date('Y-m-d H:i:s');
        if (!$userRuleId) {
            $userRuleId = Db::table('wa_rules')->insertGetId([
                'title' => 'GameAssist用户',
                'icon' => '',
                'key' => GameAssistQuotaLogPermission::USER_RULE_KEY,
                'pid' => 0,
                'href' => '/app/admin/game-assist-user/index',
                'type' => 1,
                'weight' => 800,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        if (!$quotaLogRuleId) {
            $quotaLogRuleId = Db::table('wa_rules')->insertGetId([
                'title' => '配额日志',
                'icon' => '',
                'key' => GameAssistQuotaLogPermission::QUOTA_LOG_RULE_KEY,
                'pid' => 0,
                'href' => '/app/admin/game-assist-user/quota-logs',
                'type' => 1,
                'weight' => 790,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return [(int)$userRuleId, (int)$quotaLogRuleId];
    }
}
