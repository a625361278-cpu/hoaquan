<?php

namespace app\service;

use app\exception\ApiException;
use app\repository\UserRepositoryInterface;
use app\support\ApiResponse;
use app\support\I18n;

class AuthService
{
    public const MODE_SECURITY_QUESTION = 'security_question';
    public const MODE_EMAIL_CODE = 'email_code';

    private const EMAIL_PURPOSE_REGISTER = 'register';
    private const EMAIL_PURPOSE_PASSWORD_RESET = 'password_reset';
    private const SECURITY_QUESTIONS = [
        'first_pet' => 'api.auth.security_question.first_pet',
        'birth_city' => 'api.auth.security_question.birth_city',
        'favorite_game' => 'api.auth.security_question.favorite_game',
    ];

    public function __construct(
        private UserRepositoryInterface $users,
        private TokenStoreInterface $tokens,
        private ?EmailCodeStoreInterface $emailCodes = null,
        private ?MailerInterface $mailer = null,
        private string $locale = I18n::DEFAULT_LOCALE,
        private string $verificationMode = self::MODE_SECURITY_QUESTION,
        private int $registrationRewardPoints = SystemSettingService::DEFAULT_REGISTRATION_REWARD_POINTS
    ) {
        $this->locale = I18n::normalizeLocale($this->locale);
        $this->verificationMode = $this->normalizeVerificationMode($this->verificationMode);
        if ($this->registrationRewardPoints < SystemSettingService::MIN_REGISTRATION_REWARD_POINTS
            || $this->registrationRewardPoints > SystemSettingService::MAX_REGISTRATION_REWARD_POINTS) {
            throw new \InvalidArgumentException('Registration reward points are outside the allowed range');
        }
    }

    public function login(string $account, string $password): array
    {
        $account = trim($account);
        if ($account === '' || $password === '') {
            throw new ApiException($this->t('api.auth.invalid_credentials'), 401);
        }

        $user = $this->users->findActiveByAccount($account);
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            throw new ApiException($this->t('api.auth.invalid_credentials'), 401);
        }

        return ApiResponse::success([
            'token' => $this->tokens->create((int)$user['id']),
            'user' => $this->publicUser($user),
        ]);
    }

    public function sendRegisterEmailCode(string $email): array
    {
        $this->assertEmailCodeMode();
        $email = $this->normalizeEmail($email);
        if ($this->users->emailExists($email)) {
            throw new ApiException($this->t('api.auth.email_exists'), 409);
        }
        if (!$this->emailCodes || !$this->mailer) {
            throw new ApiException($this->t('api.auth.email_code_service_not_initialized'), 500);
        }

        $this->emailCodes->assertCanSend($email, self::EMAIL_PURPOSE_REGISTER);
        $code = (string)random_int(100000, 999999);
        $this->mailer->send(
            $email,
            $this->t('api.email.register_subject'),
            $this->t('api.email.code_body', [
                'purpose' => $this->t('api.email.register_purpose'),
                'code' => $code,
            ])
        );
        $this->emailCodes->store($email, $code, self::EMAIL_PURPOSE_REGISTER);

        return ApiResponse::success(['cooldown_seconds' => 60], $this->t('api.auth.email_code_sent'));
    }

    public function sendPasswordResetEmailCode(string $account, string $email): array
    {
        $this->assertEmailCodeMode();
        $account = trim($account);
        if ($account === '') {
            throw new ApiException($this->t('api.auth.require_username'), 422);
        }

        $email = $this->normalizeEmail($email);
        $user = $this->users->findActiveByAccount($account);
        if (!$user) {
            throw new ApiException($this->t('api.auth.user_not_found'), 404);
        }
        if (strtolower((string)($user['email'] ?? '')) !== $email) {
            throw new ApiException($this->t('api.auth.email_mismatch'), 422);
        }
        if (!$this->emailCodes || !$this->mailer) {
            throw new ApiException($this->t('api.auth.email_code_service_not_initialized'), 500);
        }

        $this->emailCodes->assertCanSend($email, self::EMAIL_PURPOSE_PASSWORD_RESET);
        $code = (string)random_int(100000, 999999);
        $this->mailer->send(
            $email,
            $this->t('api.email.password_reset_subject'),
            $this->t('api.email.code_body', [
                'purpose' => $this->t('api.email.password_reset_purpose'),
                'code' => $code,
            ])
        );
        $this->emailCodes->store($email, $code, self::EMAIL_PURPOSE_PASSWORD_RESET);

        return ApiResponse::success(['cooldown_seconds' => 60], $this->t('api.auth.email_code_sent'));
    }

    public function authConfig(): array
    {
        return ApiResponse::success([
            'verification_mode' => $this->verificationMode,
            'security_questions' => $this->publicSecurityQuestions(),
        ]);
    }

    public function register(
        string $account,
        string $email,
        string $emailCode,
        string $password,
        string $passwordConfirmation,
        string $inviteCode = '',
        string $registeredIp = '',
        string $securityQuestionKey = '',
        string $securityAnswer = ''
    ): array
    {
        $account = trim($account);
        $inviteCode = $this->normalizeInviteCode($inviteCode);
        if (!preg_match('/^[A-Za-z0-9_]{4,32}$/', $account)) {
            throw new ApiException($this->t('api.auth.invalid_account_format'), 422);
        }
        if (mb_strlen($password) < 6) {
            throw new ApiException($this->t('api.auth.password_min_length'), 422);
        }
        if ($password !== $passwordConfirmation) {
            throw new ApiException($this->t('api.auth.passwords_mismatch'), 422);
        }
        if ($this->users->accountExists($account)) {
            throw new ApiException($this->t('api.auth.user_exists'), 409);
        }

        $normalizedEmail = null;
        $securityQuestionKey = trim($securityQuestionKey);
        $securityAnswerHash = null;
        if ($this->verificationMode === self::MODE_EMAIL_CODE) {
            $normalizedEmail = $this->normalizeEmail($email);
            $emailCode = trim($emailCode);
            if ($emailCode === '') {
                throw new ApiException($this->t('api.auth.require_email_code'), 422);
            }
            if ($this->users->emailExists($normalizedEmail)) {
                throw new ApiException($this->t('api.auth.email_exists'), 409);
            }
            if (!$this->emailCodes) {
                throw new ApiException($this->t('api.auth.email_code_service_not_initialized'), 500);
            }
            $this->emailCodes->verify($normalizedEmail, $emailCode, self::EMAIL_PURPOSE_REGISTER);
        } else {
            $securityQuestionKey = $this->normalizeSecurityQuestionKey($securityQuestionKey);
            $securityAnswer = $this->normalizeSecurityAnswer($securityAnswer);
            $securityAnswerHash = password_hash($securityAnswer, PASSWORD_DEFAULT);
        }
        $inviter = null;
        if ($inviteCode !== '') {
            $inviter = $this->users->findByInviteCode($inviteCode);
            if (!$inviter) {
                throw new ApiException($this->t('api.invite.invalid_code'), 422);
            }
        }

        $user = $this->users->create(
            $account,
            $normalizedEmail,
            $account,
            password_hash($password, PASSWORD_DEFAULT),
            $inviter ? (int)$inviter['id'] : null,
            trim($registeredIp),
            $this->generateUniqueInviteCode(),
            $securityQuestionKey ?: null,
            $securityAnswerHash,
            $this->registrationRewardPoints,
            $this->t('api.auth.registration_reward_description')
        );

        return ApiResponse::success([
            'token' => $this->tokens->create((int)$user['id']),
            'user' => $this->publicUser($user),
        ], $this->t('api.auth.register_success'));
    }

    public function passwordResetSecurityQuestion(string $account): array
    {
        $account = trim($account);
        if ($account === '') {
            throw new ApiException($this->t('api.auth.require_username'), 422);
        }

        $user = $this->users->findActiveByAccount($account);
        if (!$user) {
            throw new ApiException($this->t('api.auth.user_not_found'), 404);
        }

        return ApiResponse::success([
            'security_question' => $this->securityQuestionForUser($user),
        ]);
    }

    public function resetPassword(string $account, string $email, string $emailCode, string $password, string $passwordConfirmation, string $securityAnswer = ''): array
    {
        $account = trim($account);
        if ($account === '') {
            throw new ApiException($this->t('api.auth.require_username'), 422);
        }
        if (mb_strlen($password) < 6) {
            throw new ApiException($this->t('api.auth.password_min_length'), 422);
        }
        if ($password !== $passwordConfirmation) {
            throw new ApiException($this->t('api.auth.passwords_mismatch'), 422);
        }

        if ($this->verificationMode === self::MODE_EMAIL_CODE) {
            $email = $this->normalizeEmail($email);
            $emailCode = trim($emailCode);
            if ($emailCode === '') {
                throw new ApiException($this->t('api.auth.require_email_code'), 422);
            }

            $user = $this->users->findActiveByAccountAndEmail($account, $email);
            if (!$user) {
                if (!$this->users->findActiveByAccount($account)) {
                    throw new ApiException($this->t('api.auth.user_not_found'), 404);
                }
                throw new ApiException($this->t('api.auth.email_mismatch'), 422);
            }
            if (!$this->emailCodes) {
                throw new ApiException($this->t('api.auth.email_code_service_not_initialized'), 500);
            }

            $this->emailCodes->verify($email, $emailCode, self::EMAIL_PURPOSE_PASSWORD_RESET);
        } else {
            $user = $this->users->findActiveByAccount($account);
            if (!$user) {
                throw new ApiException($this->t('api.auth.user_not_found'), 404);
            }
            $this->securityQuestionForUser($user);
            $securityAnswer = $this->normalizeSecurityAnswer($securityAnswer);
            if (!password_verify($securityAnswer, (string)($user['security_answer_hash'] ?? ''))) {
                throw new ApiException($this->t('api.auth.security_answer_invalid'), 422);
            }
        }

        $this->users->updatePasswordHash((int)$user['id'], password_hash($password, PASSWORD_DEFAULT));

        return ApiResponse::success([], $this->t('api.auth.password_reset_success'));
    }

    public function changePassword(string $token, string $currentPassword, string $password, string $passwordConfirmation): array
    {
        $userId = $this->tokens->getUserId($token);
        if (!$userId) {
            throw new ApiException($this->t('api.auth.login_expired'), 401);
        }

        $user = $this->users->findActiveById($userId);
        if (!$user) {
            throw new ApiException($this->t('api.auth.login_expired'), 401);
        }
        if ($currentPassword === '' || !password_verify($currentPassword, (string)$user['password_hash'])) {
            throw new ApiException($this->t('api.auth.current_password_invalid'), 422);
        }
        if (mb_strlen($password) < 6) {
            throw new ApiException($this->t('api.auth.password_min_length'), 422);
        }
        if ($password !== $passwordConfirmation) {
            throw new ApiException($this->t('api.auth.passwords_mismatch'), 422);
        }

        $this->users->updatePasswordHash((int)$user['id'], password_hash($password, PASSWORD_DEFAULT));
        $this->tokens->delete($token);

        return ApiResponse::success([], $this->t('api.auth.password_change_success'));
    }

    public function currentUser(string $token): array
    {
        $userId = $this->tokens->getUserId($token);
        if (!$userId) {
            throw new ApiException($this->t('api.auth.login_expired'), 401);
        }

        $user = $this->users->findActiveById($userId);
        if (!$user) {
            throw new ApiException($this->t('api.auth.login_expired'), 401);
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
            throw new ApiException($this->t('api.auth.login_expired'), 401);
        }
        return $userId;
    }

    private function publicUser(array $user): array
    {
        $inviteCode = (string)($user['invite_code'] ?? '');
        if ($inviteCode === '') {
            $user = $this->users->updateInviteCode((int)$user['id'], $this->generateUniqueInviteCode());
            $inviteCode = (string)$user['invite_code'];
        }

        return [
            'id' => (int)$user['id'],
            'account' => (string)$user['account'],
            'email' => (string)($user['email'] ?? ''),
            'nickname' => (string)($user['nickname'] ?? ''),
            'avatar' => (string)($user['avatar'] ?? ''),
            'balance' => (string)($user['balance'] ?? '0.00'),
            'expire_at' => $user['expire_at'] ?? null,
            'invite_code' => $inviteCode,
            'bound_role_id' => $user['bound_role_id'] ?? null,
            'role_bound_at' => $user['role_bound_at'] ?? null,
        ];
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException($this->t('api.auth.email_invalid'), 422);
        }
        return $email;
    }

    private function assertEmailCodeMode(): void
    {
        if ($this->verificationMode !== self::MODE_EMAIL_CODE) {
            throw new ApiException($this->t('api.auth.email_verification_disabled'), 403);
        }
    }

    private function normalizeVerificationMode(string $mode): string
    {
        $mode = trim($mode);
        if (!in_array($mode, [self::MODE_SECURITY_QUESTION, self::MODE_EMAIL_CODE], true)) {
            throw new \RuntimeException('认证方式配置错误：' . $mode);
        }
        return $mode;
    }

    private function publicSecurityQuestions(): array
    {
        $questions = [];
        foreach (self::SECURITY_QUESTIONS as $key => $labelKey) {
            $questions[] = [
                'key' => $key,
                'label' => $this->t($labelKey),
            ];
        }
        return $questions;
    }

    private function normalizeSecurityQuestionKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new ApiException($this->t('api.auth.require_security_question'), 422);
        }
        if (!array_key_exists($key, self::SECURITY_QUESTIONS)) {
            throw new ApiException($this->t('api.auth.security_question_invalid'), 422);
        }
        return $key;
    }

    private function normalizeSecurityAnswer(string $answer): string
    {
        $answer = trim($answer);
        if ($answer === '') {
            throw new ApiException($this->t('api.auth.require_security_answer'), 422);
        }
        if (mb_strlen($answer) > 128) {
            throw new ApiException($this->t('api.auth.security_answer_too_long'), 422);
        }
        return $answer;
    }

    private function securityQuestionForUser(array $user): array
    {
        $key = (string)($user['security_question_key'] ?? '');
        $hash = (string)($user['security_answer_hash'] ?? '');
        if ($key === '' || $hash === '') {
            throw new ApiException($this->t('api.auth.security_question_not_set'), 422);
        }
        if (!array_key_exists($key, self::SECURITY_QUESTIONS)) {
            throw new \RuntimeException('用户密保问题配置异常：' . $key);
        }
        return [
            'key' => $key,
            'label' => $this->t(self::SECURITY_QUESTIONS[$key]),
        ];
    }

    private function t(string $key, array $parameters = []): string
    {
        return I18n::t($key, $parameters, $this->locale);
    }

    private function normalizeInviteCode(string $inviteCode): string
    {
        $inviteCode = strtoupper(trim($inviteCode));
        if ($inviteCode !== '' && !preg_match('/^[A-Z0-9]{5,16}$/', $inviteCode)) {
            throw new ApiException($this->t('api.invite.invalid_code'), 422);
        }
        return $inviteCode;
    }

    private function generateUniqueInviteCode(): string
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = $this->randomInviteCode();
            if (!$this->users->inviteCodeExists($code)) {
                return $code;
            }
        }

        throw new \RuntimeException('邀请码生成失败，请重试');
    }

    private function randomInviteCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }
}
