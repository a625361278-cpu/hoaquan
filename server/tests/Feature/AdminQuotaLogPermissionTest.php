<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;
use plugin\admin\app\model\Role;
use plugin\admin\app\service\GameAssistQuotaLogPermission;

class AdminQuotaLogPermissionTest extends TestCase
{
    public function testQuotaLogPermissionFollowsGameAssistUserPermission(): void
    {
        $connection = (new Role())->getConnection();
        $connection->beginTransaction();

        try {
            [$userRuleId, $quotaLogRuleId] = $this->ensureRules($connection);

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
        $connection = (new Role())->getConnection();
        $connection->beginTransaction();

        try {
            [$userRuleId, $quotaLogRuleId] = $this->ensureRules($connection);
            $now = date('Y-m-d H:i:s');
            $withUserRoleId = (int)$connection->table('wa_roles')->insertGetId([
                'name' => 'quota-with-user-' . bin2hex(random_bytes(3)),
                'rules' => (string)$userRuleId,
                'created_at' => $now,
                'updated_at' => $now,
                'pid' => 1,
            ]);
            $withoutUserRoleId = (int)$connection->table('wa_roles')->insertGetId([
                'name' => 'quota-without-user-' . bin2hex(random_bytes(3)),
                'rules' => (string)$quotaLogRuleId,
                'created_at' => $now,
                'updated_at' => $now,
                'pid' => 1,
            ]);

            GameAssistQuotaLogPermission::syncExistingRoles();

            $withUserRules = explode(',', (string)$connection->table('wa_roles')->where('id', $withUserRoleId)->value('rules'));
            $withoutUserRules = explode(',', (string)$connection->table('wa_roles')->where('id', $withoutUserRoleId)->value('rules'));
            $this->assertContains((string)$quotaLogRuleId, $withUserRules);
            $this->assertNotContains((string)$quotaLogRuleId, $withoutUserRules);
        } finally {
            $connection->rollBack();
        }
    }

    private function ensureRules($connection): array
    {
        $userRuleId = $connection->table('wa_rules')->where('key', GameAssistQuotaLogPermission::USER_RULE_KEY)->value('id');
        $quotaLogRuleId = $connection->table('wa_rules')->where('key', GameAssistQuotaLogPermission::QUOTA_LOG_RULE_KEY)->value('id');
        $now = date('Y-m-d H:i:s');
        if (!$userRuleId) {
            $userRuleId = $connection->table('wa_rules')->insertGetId([
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
            $quotaLogRuleId = $connection->table('wa_rules')->insertGetId([
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
