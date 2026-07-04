<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;

class AdminDashboardI18nTest extends TestCase
{
    public function testDashboardVisibleLabelsUseTranslationKeys(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/plugin/admin/app/view/index/dashboard.html');

        $this->assertStringContainsString("admin_t('admin.dashboard.today_users')", $template);
        $this->assertStringContainsString("admin_t('admin.dashboard.system_info')", $template);
        $this->assertStringNotContainsString('GameAssist 今日注册', $template);
        $this->assertStringNotContainsString('系统信息', $template);
    }
}
