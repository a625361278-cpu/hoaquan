<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;
use plugin\admin\app\common\Util;
use plugin\admin\app\controller\GameAssistUserController;

class AdminPermissionCodeTest extends TestCase
{
    public function testControllerPermissionCodeUsesAdminRoutePath(): void
    {
        $this->assertSame(
            '/app/admin/game-assist-user',
            Util::controllerToUrlPath(GameAssistUserController::class)
        );
    }

    public function testControllerActionPermissionCodeUsesAdminRoutePath(): void
    {
        $this->assertSame(
            '/app/admin/game-assist-user/grant-quota',
            Util::controllerToUrlPath(GameAssistUserController::class . '@grantQuota')
        );

        $this->assertSame(
            '/app/admin/game-assist-user/reset-password',
            Util::controllerToUrlPath(GameAssistUserController::class . '@resetPassword')
        );
    }
}
