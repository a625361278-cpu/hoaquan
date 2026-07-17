<?php

namespace app\service;

use app\exception\ApiException;
use app\repository\DbUserRepository;
use app\support\ApiResponse;
use app\support\I18n;
use support\Db;

class ProfileService
{
    private const ROLE_ID_PATTERN = '/^[^\p{C}\s]{1,128}$/u';

    public function __construct(
        private DbUserRepository $users,
        private SystemSettingService $settings,
        private string $locale = I18n::DEFAULT_LOCALE
    ) {
        $this->locale = I18n::normalizeLocale($this->locale);
    }

    public function summary(int $userId, string $origin): array
    {
        $user = $this->requireUser($userId);
        $inviteCode = (string)($user['invite_code'] ?? '');
        if ($inviteCode === '') {
            $user = $this->users->updateInviteCode($userId, $this->generateUniqueInviteCode());
            $inviteCode = (string)$user['invite_code'];
        }

        return ApiResponse::success([
            'user' => $this->publicUser($user),
            'invite' => [
                'code' => $inviteCode,
                'link' => $this->buildInviteLink($origin, $inviteCode),
                'invited_count' => $this->rewardedInviteCount($userId),
                'min_role_level' => $this->settings->inviteRewardMinRoleLevel(),
            ],
            'role_binding' => [
                'role_id' => $user['bound_role_id'] ?? null,
                'bound_at' => $user['role_bound_at'] ?? null,
            ],
            'transactions' => $this->latestTransactions($userId),
        ]);
    }

    public function bindStartedAccount(array $account, array $payload): array
    {
        $userId = (int)($account['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new ApiException($this->t('api.profile.account_user_invalid'), 422);
        }

        return $this->bindRoleForUser($userId, $this->startedRoleId($account, $payload));
    }

    private function bindRoleForUser(int $userId, string $roleId): array
    {
        $roleId = trim($roleId);
        if (!preg_match(self::ROLE_ID_PATTERN, $roleId)) {
            throw new ApiException($this->t('api.profile.role_id_invalid'), 422);
        }

        $user = $this->requireUser($userId);
        if (!empty($user['bound_role_id'])) {
            return ApiResponse::success([
                'role_binding' => [
                    'role_id' => (string)$user['bound_role_id'],
                    'bound_at' => $user['role_bound_at'] ?? null,
                ],
                'rewarded' => false,
                'reward_message' => '',
            ], $this->t('api.profile.role_bind_success'));
        }
        if ($this->roleBoundByOtherUser($userId, $roleId)) {
            throw new ApiException($this->t('api.profile.role_used'), 409);
        }
        $now = date('Y-m-d H:i:s');
        Db::connection()->transaction(function () use ($userId, $roleId, $now) {
            $updated = Db::table('ga_users')
                ->where('id', $userId)
                ->whereNull('bound_role_id')
                ->update([
                    'bound_role_id' => $roleId,
                    'role_bound_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                throw new ApiException($this->t('api.profile.role_already_bound'), 409);
            }
        });

        return ApiResponse::success([
            'role_binding' => [
                'role_id' => $roleId,
                'bound_at' => $now,
            ],
            'rewarded' => false,
            'reward_message' => '',
        ], $this->t('api.profile.role_bind_success'));
    }

    private function startedRoleId(array $account, array $payload): string
    {
        $roleId = trim((string)($payload['role_id'] ?? ''));
        if ($roleId !== '') {
            return $roleId;
        }

        $loginMethod = (int)($account['login_method'] ?? GameAccountLoginMethod::ACCOUNT_PASSWORD);
        return trim((string)($loginMethod === GameAccountLoginMethod::ACCOUNT_PASSWORD
            ? ($account['game_username'] ?? '')
            : ($account['game_uid'] ?? '')));
    }

    private function requireUser(int $userId): array
    {
        $user = $this->users->findActiveById($userId);
        if (!$user) {
            throw new ApiException($this->t('api.auth.login_expired'), 401);
        }
        return $user;
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
            'invite_code' => (string)($user['invite_code'] ?? ''),
            'bound_role_id' => $user['bound_role_id'] ?? null,
            'role_bound_at' => $user['role_bound_at'] ?? null,
        ];
    }

    private function rewardedInviteCount(int $userId): int
    {
        return Db::table('ga_users')
            ->where('invited_by_user_id', $userId)
            ->whereNotNull('invite_rewarded_at')
            ->count();
    }

    private function latestTransactions(int $userId): array
    {
        return Db::table('ga_user_point_transactions')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(static fn ($row): array => [
                'id' => (int)$row->id,
                'type' => (string)$row->type,
                'amount' => (string)$row->amount,
                'balance_after' => (string)$row->balance_after,
                'description' => (string)$row->description,
                'related_role_id' => (string)$row->related_role_id,
                'created_at' => (string)$row->created_at,
            ])
            ->all();
    }

    private function roleBoundByOtherUser(int $userId, string $roleId): bool
    {
        return Db::table('ga_users')
            ->where('bound_role_id', $roleId)
            ->where('id', '<>', $userId)
            ->exists();
    }

    private function buildInviteLink(string $origin, string $inviteCode): string
    {
        $origin = rtrim($origin, '/');
        if ($origin === '') {
            $origin = '/';
        }
        return $origin . '/#/pages/login/index?invite=' . rawurlencode($inviteCode);
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

    private function t(string $key, array $parameters = []): string
    {
        return I18n::t($key, $parameters, $this->locale);
    }
}
