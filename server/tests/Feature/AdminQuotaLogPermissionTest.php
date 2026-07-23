<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;
use plugin\admin\app\model\Role;
use plugin\admin\app\service\GameAssistQuotaLogPermission;

class AdminQuotaLogPermissionTest extends TestCase
{
    public function testQuotaLogPermissionKeepsExplicitRoleRules(): void
    {
        $connection = (new Role())->getConnection();
        $connection->beginTransaction();

        try {
            [$userRuleId, $quotaLogRuleId, $quotaGrantRuleId, $quotaConsumeRuleId] = $this->ensureRules($connection);

            $withUser = GameAssistQuotaLogPermission::normalizeRuleString('999999,' . $userRuleId);
            $withQuotaLog = GameAssistQuotaLogPermission::normalizeRuleString('999999,' . $quotaLogRuleId);
            $withQuotaGrantAction = GameAssistQuotaLogPermission::normalizeRuleString('999999,' . $quotaGrantRuleId);
            $withQuotaConsumeAction = GameAssistQuotaLogPermission::normalizeRuleString('999999,' . $quotaConsumeRuleId);

            $this->assertSame('999999,' . $userRuleId, $withUser);
            $this->assertSame('999999,' . $quotaLogRuleId, $withQuotaLog);
            $this->assertSame('999999,' . $quotaGrantRuleId, $withQuotaGrantAction);
            $this->assertSame('999999,' . $quotaConsumeRuleId, $withQuotaConsumeAction);
            $this->assertSame('*', GameAssistQuotaLogPermission::normalizeRuleString('*'));
        } finally {
            $connection->rollBack();
        }
    }

    public function testSyncExistingRolesDoesNotRewriteSelectedPermissions(): void
    {
        $connection = (new Role())->getConnection();
        $connection->beginTransaction();

        try {
            [$userRuleId, $quotaLogRuleId, , $quotaConsumeRuleId] = $this->ensureRules($connection);
            $now = date('Y-m-d H:i:s');
            $withUserRoleId = (int)$connection->table('wa_roles')->insertGetId([
                'name' => 'quota-with-user-' . bin2hex(random_bytes(3)),
                'rules' => (string)$userRuleId,
                'created_at' => $now,
                'updated_at' => $now,
                'pid' => 1,
            ]);
            $withQuotaActionRoleId = (int)$connection->table('wa_roles')->insertGetId([
                'name' => 'quota-with-action-' . bin2hex(random_bytes(3)),
                'rules' => (string)$quotaConsumeRuleId,
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

            $this->assertSame((string)$userRuleId, (string)$connection->table('wa_roles')->where('id', $withUserRoleId)->value('rules'));
            $this->assertSame((string)$quotaConsumeRuleId, (string)$connection->table('wa_roles')->where('id', $withQuotaActionRoleId)->value('rules'));
            $this->assertSame((string)$quotaLogRuleId, (string)$connection->table('wa_roles')->where('id', $withoutUserRoleId)->value('rules'));
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
        $quotaGrantRuleId = $connection->table('wa_rules')
            ->where('key', GameAssistQuotaLogPermission::USER_RULE_KEY . '@quotaGrantRecords')
            ->value('id');
        if (!$quotaGrantRuleId) {
            $quotaGrantRuleId = $connection->table('wa_rules')->insertGetId([
                'title' => '管理员添加记录',
                'icon' => '',
                'key' => GameAssistQuotaLogPermission::USER_RULE_KEY . '@quotaGrantRecords',
                'pid' => $userRuleId,
                'href' => '',
                'type' => 2,
                'weight' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $quotaConsumeRuleId = $connection->table('wa_rules')
            ->where('key', GameAssistQuotaLogPermission::USER_RULE_KEY . '@quotaConsumeRecords')
            ->value('id');
        if (!$quotaConsumeRuleId) {
            $quotaConsumeRuleId = $connection->table('wa_rules')->insertGetId([
                'title' => '用户配额记录',
                'icon' => '',
                'key' => GameAssistQuotaLogPermission::USER_RULE_KEY . '@quotaConsumeRecords',
                'pid' => $userRuleId,
                'href' => '',
                'type' => 2,
                'weight' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return [(int)$userRuleId, (int)$quotaLogRuleId, (int)$quotaGrantRuleId, (int)$quotaConsumeRuleId];
    }
}
