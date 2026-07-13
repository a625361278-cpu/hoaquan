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
        $service = $this->makeService(emailCodes: $codes, verificationMode: 'email_code');

        $result = $service->register('new_player', 'new@example.com', '123456', 'secret123', 'secret123');

        $this->assertSame(0, $result['code']);
        $this->assertSame('注册成功', $result['msg']);
        $this->assertNotEmpty($result['data']['token']);
        $this->assertSame('new_player', $result['data']['user']['account']);
        $this->assertSame('new@example.com', $result['data']['user']['email']);
        $this->assertSame('new_player', $result['data']['user']['nickname']);
        $this->assertSame('1.00', $result['data']['user']['balance']);
        $this->assertArrayNotHasKey('password_hash', $result['data']['user']);
    }

    public function testRegisterCreatesUserWithSecurityQuestionInDefaultMode(): void
    {
        $users = $this->makeUsers();
        $service = $this->makeService(users: $users);

        $result = $service->register('new_player', '', '', 'secret123', 'secret123', '', '', 'first_pet', 'Mimi');

        $this->assertSame(0, $result['code']);
        $this->assertSame('注册成功', $result['msg']);
        $this->assertSame('new_player', $result['data']['user']['account']);
        $this->assertSame('', $result['data']['user']['email']);
        $this->assertSame('1.00', $result['data']['user']['balance']);
        $this->assertCount(1, $users->pointTransactions);
        $this->assertSame('registration_reward', $users->pointTransactions[0]['type']);
        $this->assertSame('1.00', $users->pointTransactions[0]['amount']);

        $user = $users->findActiveByAccount('new_player');
        $this->assertSame('first_pet', $user['security_question_key']);
        $this->assertNotSame('Mimi', $user['security_answer_hash']);
        $this->assertTrue(password_verify('Mimi', $user['security_answer_hash']));
    }

    public function testRegisterUsesConfiguredRewardPoints(): void
    {
        $users = $this->makeUsers();
        $service = $this->makeService(users: $users, registrationRewardPoints: 7);

        $result = $service->register('rewarded_player', '', '', 'secret123', 'secret123', '', '10.2.3.4', 'first_pet', 'Mimi');

        $this->assertSame('7.00', $result['data']['user']['balance']);
        $this->assertCount(1, $users->pointTransactions);
        $this->assertSame('7.00', $users->pointTransactions[0]['balance_after']);
        $this->assertSame('10.2.3.4', $users->pointTransactions[0]['ip_address']);
    }

    public function testRegisterWithZeroRewardCreatesNoPointTransaction(): void
    {
        $users = $this->makeUsers();
        $service = $this->makeService(users: $users, registrationRewardPoints: 0);

        $result = $service->register('zero_reward', '', '', 'secret123', 'secret123', '', '', 'first_pet', 'Mimi');

        $this->assertSame('0.00', $result['data']['user']['balance']);
        $this->assertSame([], $users->pointTransactions);
    }

    public function testInvitedUserGetsRegistrationRewardWithoutImmediatelyRewardingInviter(): void
    {
        $users = $this->makeUsers();
        $service = $this->makeService(users: $users);

        $result = $service->register('invited_new', '', '', 'secret123', 'secret123', 'PLAYER01', '10.2.3.4', 'first_pet', 'Mimi');

        $created = $users->findActiveByAccount('invited_new');
        $this->assertSame(1, $created['invited_by_user_id']);
        $this->assertSame('1.00', $result['data']['user']['balance']);
        $this->assertCount(1, $users->pointTransactions);
        $this->assertSame('registration_reward', $users->pointTransactions[0]['type']);
        $this->assertSame('0.00', $users->findActiveByAccount('player001')['balance']);
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
        $service = $this->makeService(verificationMode: 'email_code');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('邮箱已注册');

        $service->register('new_player', 'player001@example.com', '123456', 'secret123', 'secret123');
    }

    public function testRegisterRejectsWrongEmailCode(): void
    {
        $codes = new MemoryEmailCodeStore();
        $codes->forceCode('new@example.com', '123456');
        $service = $this->makeService(emailCodes: $codes, verificationMode: 'email_code');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('邮箱验证码错误');

        $service->register('new_player', 'new@example.com', '000000', 'secret123', 'secret123');
    }

    public function testSendEmailCodeFailsWhenMailerIsDisabled(): void
    {
        $service = $this->makeService(mailer: new MemoryMailer(false), verificationMode: 'email_code');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('SMTP未启用，无法发送邮箱验证码');

        $service->sendRegisterEmailCode('new@example.com');
    }

    public function testSendEmailCodeRejectsDuplicateEmail(): void
    {
        $service = $this->makeService(verificationMode: 'email_code');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('邮箱已注册');

        $service->sendRegisterEmailCode('player001@example.com');
    }

    public function testSendEmailCodeFailsWhenEmailVerificationIsDisabled(): void
    {
        $service = $this->makeService();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('邮箱验证已关闭');

        $service->sendRegisterEmailCode('new@example.com');
    }

    public function testSendPasswordResetEmailCodeRequiresMatchingEmail(): void
    {
        $service = $this->makeService(verificationMode: 'email_code');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('邮箱与账号不匹配');

        $service->sendPasswordResetEmailCode('player001', 'wrong@example.com');
    }

    public function testSendPasswordResetEmailCodeSendsForMatchingAccountAndEmail(): void
    {
        $mailer = new MemoryMailer();
        $service = $this->makeService(mailer: $mailer, verificationMode: 'email_code');

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
        $service = $this->makeService(emailCodes: $codes, verificationMode: 'email_code');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('邮箱验证码错误');

        $service->resetPassword('player001', 'player001@example.com', '000000', 'newsecret', 'newsecret');
    }

    public function testResetPasswordRejectsRegisterCode(): void
    {
        $codes = new MemoryEmailCodeStore();
        $codes->forceCode('player001@example.com', '123456', 'register');
        $service = $this->makeService(emailCodes: $codes, verificationMode: 'email_code');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('邮箱验证码已过期或未发送');

        $service->resetPassword('player001', 'player001@example.com', '123456', 'newsecret', 'newsecret');
    }

    public function testResetPasswordRejectsPasswordConfirmationMismatch(): void
    {
        $codes = new MemoryEmailCodeStore();
        $codes->forceCode('player001@example.com', '123456', 'password_reset');
        $service = $this->makeService(emailCodes: $codes, verificationMode: 'email_code');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('两次输入的密码不一致');

        $service->resetPassword('player001', 'player001@example.com', '123456', 'newsecret', 'othersecret');
    }

    public function testResetPasswordUpdatesPasswordHashAndDoesNotAutoLogin(): void
    {
        $codes = new MemoryEmailCodeStore();
        $codes->forceCode('player001@example.com', '123456', 'password_reset');
        $service = $this->makeService(emailCodes: $codes, verificationMode: 'email_code');

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

    public function testPasswordResetReturnsSecurityQuestionInDefaultMode(): void
    {
        $service = $this->makeService();

        $result = $service->passwordResetSecurityQuestion('player001');

        $this->assertSame(0, $result['code']);
        $this->assertSame('first_pet', $result['data']['security_question']['key']);
        $this->assertSame('你的第一个宠物叫什么？', $result['data']['security_question']['label']);
    }

    public function testResetPasswordRejectsWrongSecurityAnswer(): void
    {
        $service = $this->makeService();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('密保答案错误');

        $service->resetPassword('player001', '', '', 'newsecret', 'newsecret', 'Wrong');
    }

    public function testResetPasswordUpdatesPasswordWithSecurityAnswer(): void
    {
        $service = $this->makeService();

        $result = $service->resetPassword('player001', '', '', 'newsecret', 'newsecret', 'Mimi');

        $this->assertSame(0, $result['code']);
        $this->assertSame('密码重置成功，请重新登录', $result['msg']);

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

    public function testPasswordResetRejectsUserWithoutSecurityQuestion(): void
    {
        $users = new ArrayUserRepository([
            [
                'id' => 1,
                'account' => 'legacy001',
                'email' => 'legacy001@example.com',
                'nickname' => '旧用户',
                'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
                'avatar' => '',
                'balance' => '0.00',
                'expire_at' => null,
                'status' => 1,
            ],
        ]);
        $service = $this->makeService(users: $users);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('该账号未设置密保问题，请联系管理员处理');

        $service->passwordResetSecurityQuestion('legacy001');
    }

    public function testChangePasswordUpdatesHashAndInvalidatesCurrentToken(): void
    {
        $service = $this->makeService();
        $token = $service->login('player001', 'secret123')['data']['token'];

        $result = $service->changePassword($token, 'secret123', 'newsecret', 'newsecret');

        $this->assertSame(0, $result['code']);
        $this->assertSame('密码修改成功，请重新登录', $result['msg']);

        try {
            $service->currentUser($token);
            $this->fail('修改密码成功后当前 token 应失效');
        } catch (ApiException $exception) {
            $this->assertSame('登录已失效，请重新登录', $exception->getMessage());
        }

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

    public function testChangePasswordRejectsWrongCurrentPassword(): void
    {
        $service = $this->makeService();
        $token = $service->login('player001', 'secret123')['data']['token'];

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('当前密码错误');

        $service->changePassword($token, 'wrong-password', 'newsecret', 'newsecret');
    }

    public function testChangePasswordRejectsShortNewPassword(): void
    {
        $service = $this->makeService();
        $token = $service->login('player001', 'secret123')['data']['token'];

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('密码至少需要6位');

        $service->changePassword($token, 'secret123', 'short', 'short');
    }

    public function testChangePasswordRejectsPasswordConfirmationMismatch(): void
    {
        $service = $this->makeService();
        $token = $service->login('player001', 'secret123')['data']['token'];

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('两次输入的密码不一致');

        $service->changePassword($token, 'secret123', 'newsecret', 'othersecret');
    }

    public function testChangePasswordRequiresValidToken(): void
    {
        $service = $this->makeService();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('登录已失效，请重新登录');

        $service->changePassword('missing-token', 'secret123', 'newsecret', 'newsecret');
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

    private function makeService(?MemoryEmailCodeStore $emailCodes = null, ?MemoryMailer $mailer = null, ?ArrayUserRepository $users = null, string $verificationMode = 'security_question', int $registrationRewardPoints = 1): AuthService
    {
        return new AuthService(
            $users ?? $this->makeUsers(),
            new MemoryTokenStore(),
            $emailCodes ?? new MemoryEmailCodeStore(),
            $mailer ?? new MemoryMailer(),
            'zh_CN',
            $verificationMode,
            $registrationRewardPoints
        );
    }

    private function makeUsers(): ArrayUserRepository
    {
        return new ArrayUserRepository([
            [
                'id' => 1,
                'account' => 'player001',
                'email' => 'player001@example.com',
                'nickname' => '玩家001',
                'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
                'avatar' => '',
                'balance' => '0.00',
                'expire_at' => null,
                'invite_code' => 'PLAYER01',
                'security_question_key' => 'first_pet',
                'security_answer_hash' => password_hash('Mimi', PASSWORD_DEFAULT),
                'status' => 1,
            ],
        ]);
    }
}
