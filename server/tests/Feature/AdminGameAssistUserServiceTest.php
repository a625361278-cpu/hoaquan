<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;
use plugin\admin\app\service\GameAssistUserAdminService;
use RuntimeException;

class AdminGameAssistUserServiceTest extends TestCase
{
    public function testSanitizesRowsWithoutPasswordHash(): void
    {
        $service = new GameAssistUserAdminService();

        $rows = $service->sanitizeRows([
            (object)[
                'id' => 1,
                'account' => 'player001',
                'email' => 'player001@example.com',
                'nickname' => '玩家001',
                'password_hash' => 'secret-hash',
                'balance' => '0.00',
                'expire_at' => null,
                'status' => 1,
                'created_at' => '2026-07-01 10:00:00',
                'updated_at' => '2026-07-01 10:00:00',
            ],
        ]);

        $this->assertSame('player001', $rows[0]['account']);
        $this->assertArrayNotHasKey('password_hash', $rows[0]);
    }

    public function testSanitizesModelLikeRowsWithToArray(): void
    {
        $service = new GameAssistUserAdminService();
        $row = new class {
            public function toArray(): array
            {
                return [
                    'id' => 1,
                    'account' => 'player001',
                    'password_hash' => 'secret-hash',
                ];
            }
        };

        $rows = $service->sanitizeRows([$row]);

        $this->assertSame(['id' => 1, 'account' => 'player001'], $rows[0]);
    }

    public function testOnlyStatusCanBeUpdatedFromGenericAdminUpdate(): void
    {
        $service = new GameAssistUserAdminService();

        $filtered = $service->filterStatusUpdate([
            'id' => 1,
            'status' => '0',
            'balance' => '999.00',
            'expire_at' => '2099-01-01',
            'password_hash' => 'plain-text',
        ]);

        $this->assertSame(['status' => 0], $filtered);
    }

    public function testRejectsInvalidStatusUpdate(): void
    {
        $service = new GameAssistUserAdminService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('用户状态值异常');

        $service->filterStatusUpdate(['status' => '2']);
    }

    public function testBuildPasswordHashRejectsShortPassword(): void
    {
        $service = new GameAssistUserAdminService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('密码至少需要6位');

        $service->buildPasswordHash('12345');
    }

    public function testBuildPasswordHashUsesPasswordHash(): void
    {
        $service = new GameAssistUserAdminService();

        $hash = $service->buildPasswordHash('newsecret');

        $this->assertNotSame('newsecret', $hash);
        $this->assertTrue(password_verify('newsecret', $hash));
    }
}
