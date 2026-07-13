<?php

namespace tests\Feature;

use app\exception\ApiException;
use app\service\AuthService;
use PHPUnit\Framework\TestCase;
use tests\Support\ArrayUserRepository;
use tests\Support\MemoryEmailCodeStore;
use tests\Support\MemoryMailer;
use tests\Support\MemoryTokenStore;

class AuthServiceI18nTest extends TestCase
{
    public function testLoginErrorUsesVietnameseLocale(): void
    {
        $service = $this->makeService('vi');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Sai tài khoản hoặc mật khẩu');

        $service->login('player001', 'bad-password');
    }

    public function testRegisterSuccessUsesVietnameseLocale(): void
    {
        $service = $this->makeService('vi');

        $result = $service->register('new_player', '', '', 'secret123', 'secret123', '', '', 'first_pet', 'Mimi');

        $this->assertSame(0, $result['code']);
        $this->assertSame('Đăng ký thành công', $result['msg']);
    }

    private function makeService(string $locale, ?MemoryEmailCodeStore $emailCodes = null): AuthService
    {
        return new AuthService(
            new ArrayUserRepository([
                [
                    'id' => 1,
                    'account' => 'player001',
                    'email' => 'player001@example.com',
                    'nickname' => '玩家001',
                    'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
                    'avatar' => '',
                    'balance' => '0.00',
                    'expire_at' => null,
                    'status' => 1,
                ],
            ]),
            new MemoryTokenStore(),
            $emailCodes ?? new MemoryEmailCodeStore(),
            new MemoryMailer(),
            $locale
        );
    }
}
