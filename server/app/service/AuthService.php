<?php

namespace app\service;

use app\exception\ApiException;
use app\repository\UserRepositoryInterface;
use app\support\ApiResponse;

class AuthService
{
    private const EMAIL_PURPOSE_REGISTER = 'register';
    private const EMAIL_PURPOSE_PASSWORD_RESET = 'password_reset';

    public function __construct(
        private UserRepositoryInterface $users,
        private TokenStoreInterface $tokens,
        private ?EmailCodeStoreInterface $emailCodes = null,
        private ?MailerInterface $mailer = null
    ) {
    }

    public function login(string $account, string $password): array
    {
        $account = trim($account);
        if ($account === '' || $password === '') {
            throw new ApiException('账号或密码错误', 401);
        }

        $user = $this->users->findActiveByAccount($account);
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            throw new ApiException('账号或密码错误', 401);
        }

        return ApiResponse::success([
            'token' => $this->tokens->create((int)$user['id']),
            'user' => $this->publicUser($user),
        ]);
    }

    public function sendRegisterEmailCode(string $email): array
    {
        $email = $this->normalizeEmail($email);
        if ($this->users->emailExists($email)) {
            throw new ApiException('邮箱已注册', 409);
        }
        if (!$this->emailCodes || !$this->mailer) {
            throw new ApiException('邮箱验证码服务未初始化', 500);
        }

        $this->emailCodes->assertCanSend($email, self::EMAIL_PURPOSE_REGISTER);
        $code = (string)random_int(100000, 999999);
        $this->mailer->send(
            $email,
            'Hoa Quán 注册验证码',
            "你的 Hoa Quán 注册验证码是：{$code}\n\n验证码10分钟内有效，请勿泄露给他人。"
        );
        $this->emailCodes->store($email, $code, self::EMAIL_PURPOSE_REGISTER);

        return ApiResponse::success(['cooldown_seconds' => 60], '验证码已发送');
    }

    public function sendPasswordResetEmailCode(string $account, string $email): array
    {
        $account = trim($account);
        if ($account === '') {
            throw new ApiException('请输入用户名', 422);
        }

        $email = $this->normalizeEmail($email);
        $user = $this->users->findActiveByAccount($account);
        if (!$user) {
            throw new ApiException('账号不存在', 404);
        }
        if (strtolower((string)($user['email'] ?? '')) !== $email) {
            throw new ApiException('邮箱与账号不匹配', 422);
        }
        if (!$this->emailCodes || !$this->mailer) {
            throw new ApiException('邮箱验证码服务未初始化', 500);
        }

        $this->emailCodes->assertCanSend($email, self::EMAIL_PURPOSE_PASSWORD_RESET);
        $code = (string)random_int(100000, 999999);
        $this->mailer->send(
            $email,
            'Hoa Quán 重置密码验证码',
            "你的 Hoa Quán 重置密码验证码是：{$code}\n\n验证码10分钟内有效，请勿泄露给他人。"
        );
        $this->emailCodes->store($email, $code, self::EMAIL_PURPOSE_PASSWORD_RESET);

        return ApiResponse::success(['cooldown_seconds' => 60], '验证码已发送');
    }

    public function register(string $account, string $email, string $emailCode, string $password, string $passwordConfirmation): array
    {
        $account = trim($account);
        $email = $this->normalizeEmail($email);
        $emailCode = trim($emailCode);
        if (!preg_match('/^[A-Za-z0-9_]{4,32}$/', $account)) {
            throw new ApiException('账号只能使用4-32位字母、数字或下划线', 422);
        }
        if (mb_strlen($password) < 6) {
            throw new ApiException('密码至少需要6位', 422);
        }
        if ($password !== $passwordConfirmation) {
            throw new ApiException('两次输入的密码不一致', 422);
        }
        if ($emailCode === '') {
            throw new ApiException('请输入邮箱验证码', 422);
        }
        if ($this->users->accountExists($account)) {
            throw new ApiException('账号已存在', 409);
        }
        if ($this->users->emailExists($email)) {
            throw new ApiException('邮箱已注册', 409);
        }
        if (!$this->emailCodes) {
            throw new ApiException('邮箱验证码服务未初始化', 500);
        }

        $this->emailCodes->verify($email, $emailCode, self::EMAIL_PURPOSE_REGISTER);

        $user = $this->users->create(
            $account,
            $email,
            $account,
            password_hash($password, PASSWORD_DEFAULT)
        );

        return ApiResponse::success([
            'token' => $this->tokens->create((int)$user['id']),
            'user' => $this->publicUser($user),
        ], '注册成功');
    }

    public function resetPassword(string $account, string $email, string $emailCode, string $password, string $passwordConfirmation): array
    {
        $account = trim($account);
        if ($account === '') {
            throw new ApiException('请输入用户名', 422);
        }
        $email = $this->normalizeEmail($email);
        $emailCode = trim($emailCode);
        if ($emailCode === '') {
            throw new ApiException('请输入邮箱验证码', 422);
        }
        if (mb_strlen($password) < 6) {
            throw new ApiException('密码至少需要6位', 422);
        }
        if ($password !== $passwordConfirmation) {
            throw new ApiException('两次输入的密码不一致', 422);
        }

        $user = $this->users->findActiveByAccountAndEmail($account, $email);
        if (!$user) {
            if (!$this->users->findActiveByAccount($account)) {
                throw new ApiException('账号不存在', 404);
            }
            throw new ApiException('邮箱与账号不匹配', 422);
        }
        if (!$this->emailCodes) {
            throw new ApiException('邮箱验证码服务未初始化', 500);
        }

        $this->emailCodes->verify($email, $emailCode, self::EMAIL_PURPOSE_PASSWORD_RESET);
        $this->users->updatePasswordHash((int)$user['id'], password_hash($password, PASSWORD_DEFAULT));

        return ApiResponse::success([], '密码重置成功，请重新登录');
    }

    public function currentUser(string $token): array
    {
        $userId = $this->tokens->getUserId($token);
        if (!$userId) {
            throw new ApiException('登录已失效，请重新登录', 401);
        }

        $user = $this->users->findActiveById($userId);
        if (!$user) {
            throw new ApiException('登录已失效，请重新登录', 401);
        }

        return ApiResponse::success(['user' => $this->publicUser($user)]);
    }

    public function logout(string $token): array
    {
        $this->tokens->delete($token);
        return ApiResponse::success();
    }

    public function resolveUserId(string $token): int
    {
        $userId = $this->tokens->getUserId($token);
        if (!$userId) {
            throw new ApiException('登录已失效，请重新登录', 401);
        }
        return $userId;
    }

    private function publicUser(array $user): array
    {
        return [
            'id' => (int)$user['id'],
            'account' => (string)$user['account'],
            'email' => (string)($user['email'] ?? ''),
            'nickname' => (string)($user['nickname'] ?? ''),
            'avatar' => (string)($user['avatar'] ?? ''),
            'balance' => (string)($user['balance'] ?? '0.00'),
            'expire_at' => $user['expire_at'] ?? null,
        ];
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('邮箱格式错误', 422);
        }
        return $email;
    }
}
