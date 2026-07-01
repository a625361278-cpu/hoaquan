<?php

namespace tests\Feature;

use app\exception\ApiException;
use app\service\AuthService;
use PHPUnit\Framework\TestCase;
use tests\Support\MemoryEmailCodeStore;
use tests\Support\MemoryMailer;
use tests\Support\ArrayUserRepository;
use tests\Support\MemoryTokenStore;

class AuthServiceTest extends TestCase
{
    public function testLoginReturnsTokenAndCurrentUserForValidPassword(): void
    {
        $service = $this->makeService();

        $result = $service->login('player001', 'secret123');

        $this->assertSame(0, $result['code']);
        $this->assertNotEmpty($result['data']['token']);
        $this->assertSame('player001', $result['data']['user']['account']);
        $this->assertArrayNotHasKey('password', $result['data']['user']);
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $service = $this->makeService();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('账号或密码错误');

        $service->login('player001', 'bad-password');
    }

    public function testRegisterCreatesRealUserAndReturnsToken(): void
    {
        $codes = new MemoryEmailCodeStore();
        $codes->forceCode('new@example.com', '123456');
        $service = $this->makeService(emailCodes: $codes);

        $result = $service->register('new_player', 'new@example.com', '123456', 'secret123', 'secret123');

        $this->assertSame(0, $result['code']);
        $this->assertSame('注册成功', $result['msg']);
        $this->assertNotEmpty($result['data']['token']);
        $this->assertSame('new_player', $result['data']['user']['account']);
        $this->assertSame('new@example.com', $result['data']['user']['email']);
        $this->assertSame('new_player', $result['data']['user']['nickname']);
        $this->assertArrayNotHasKey('password_hash', $result['data']['user']);
    }

    public function testRegisterRejectsDuplicateAccount(): void
    {
        $service = $this->makeService();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('账号已存在');

        $service->register('player001', 'new@example.com', '123456', 'secret123', 'secret123');
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $service = $this->makeService();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('邮箱已注册');

        $service->register('new_player', 'player001@example.com', '123456', 'secret123', 'secret123');
    }

    public function testRegisterRejectsWrongEmailCode(): void
    {
        $codes = new MemoryEmailCodeStore();
        $codes->forceCode('new@example.com', '123456');
        $service = $this->makeService(emailCodes: $codes);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('邮箱验证码错误');

        $service->register('new_player', 'new@example.com', '000000', 'secret123', 'secret123');
    }

    public function testSendEmailCodeFailsWhenMailerIsDisabled(): void
    {
        $service = $this->makeService(mailer: new MemoryMailer(false));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('SMTP未启用，无法发送邮箱验证码');

        $service->sendRegisterEmailCode('new@example.com');
    }

    public function testSendEmailCodeRejectsDuplicateEmail(): void
    {
        $service = $this->makeService();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('邮箱已注册');

        $service->sendRegisterEmailCode('player001@example.com');
    }

    public function testSendPasswordResetEmailCodeRequiresMatchingEmail(): void
    {
        $service = $this->makeService();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('邮箱与账号不匹配');

        $service->sendPasswordResetEmailCode('player001', 'wrong@example.com');
    }

    public function testSendPasswordResetEmailCodeSendsForMatchingAccountAndEmail(): void
    {
        $mailer = new MemoryMailer();
        $service = $this->makeService(mailer: $mailer);

        $result = $service->sendPasswordResetEmailCode('player001', 'player001@example.com');

        $this->assertSame(0, $result['code']);
        $this->assertSame('验证码已发送', $result['msg']);
        $this->assertSame(60, $result['data']['cooldown_seconds']);
        $this->assertCount(1, $mailer->sent);
        $this->assertSame('Hoa Quán 重置密码验证码', $mailer->sent[0]['subject']);
    }

    public function testResetPasswordRejectsWrongEmailCode(): void
    {
        $codes = new MemoryEmailCodeStore();
        $codes->forceCode('player001@example.com', '123456', 'password_reset');
        $service = $this->makeService(emailCodes: $codes);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('邮箱验证码错误');

        $service->resetPassword('player001', 'player001@example.com', '000000', 'newsecret', 'newsecret');
    }

    public function testResetPasswordRejectsRegisterCode(): void
    {
        $codes = new MemoryEmailCodeStore();
        $codes->forceCode('player001@example.com', '123456', 'register');
        $service = $this->makeService(emailCodes: $codes);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('邮箱验证码已过期或未发送');

        $service->resetPassword('player001', 'player001@example.com', '123456', 'newsecret', 'newsecret');
    }

    public function testResetPasswordRejectsPasswordConfirmationMismatch(): void
    {
        $codes = new MemoryEmailCodeStore();
        $codes->forceCode('player001@example.com', '123456', 'password_reset');
        $service = $this->makeService(emailCodes: $codes);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('两次输入的密码不一致');

        $service->resetPassword('player001', 'player001@example.com', '123456', 'newsecret', 'othersecret');
    }

    public function testResetPasswordUpdatesPasswordHashAndDoesNotAutoLogin(): void
    {
        $codes = new MemoryEmailCodeStore();
        $codes->forceCode('player001@example.com', '123456', 'password_reset');
        $service = $this->makeService(emailCodes: $codes);

        $result = $service->resetPassword('player001', 'player001@example.com', '123456', 'newsecret', 'newsecret');

        $this->assertSame(0, $result['code']);
        $this->assertSame('密码重置成功，请重新登录', $result['msg']);
        $this->assertSame([], $result['data']);

        try {
            $service->login('player001', 'secret123');
            $this->fail('旧密码不应继续可用');
        } catch (ApiException $exception) {
            $this->assertSame('账号或密码错误', $exception->getMessage());
        }

        $login = $service->login('player001', 'newsecret');
        $this->assertSame(0, $login['code']);
        $this->assertNotEmpty($login['data']['token']);
    }

    public function testCurrentUserRequiresValidToken(): void
    {
        $service = $this->makeService();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('登录已失效，请重新登录');

        $service->currentUser('missing-token');
    }

    public function testLogoutInvalidatesToken(): void
    {
        $service = $this->makeService();
        $token = $service->login('player001', 'secret123')['data']['token'];

        $service->logout($token);

        $this->expectException(ApiException::class);
        $service->currentUser($token);
    }

    private function makeService(?MemoryEmailCodeStore $emailCodes = null, ?MemoryMailer $mailer = null): AuthService
    {
        $passwordHash = password_hash('secret123', PASSWORD_DEFAULT);
        return new AuthService(
            new ArrayUserRepository([
                [
                    'id' => 1,
                    'account' => 'player001',
                    'email' => 'player001@example.com',
                    'nickname' => '玩家001',
                    'password_hash' => $passwordHash,
                    'avatar' => '',
                    'balance' => '0.00',
                    'expire_at' => null,
                    'status' => 1,
                ],
            ]),
            new MemoryTokenStore(),
            $emailCodes ?? new MemoryEmailCodeStore(),
            $mailer ?? new MemoryMailer()
        );
    }
}
