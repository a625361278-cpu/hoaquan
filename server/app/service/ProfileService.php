<?php

namespace app\service;

use app\exception\ApiException;
use app\repository\DbUserRepository;
use app\support\ApiResponse;
use app\support\I18n;
use support\Db;

class ProfileService
{
    private const INVITE_REWARD_AMOUNT = '1.00';
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
                'daily_limit' => $this->inviteDailyLimit(),
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

    private function bindRoleForUser(int $userId, string $roleId, string $ipAddress = ''): array
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
        $rewarded = false;
        $rewardMessage = '';

        Db::connection()->transaction(function () use ($userId, $roleId, $ipAddress, $now, &$rewarded, &$rewardMessage) {
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

            $freshUser = $this->requireUser($userId);
            $inviterId = (int)($freshUser['invited_by_user_id'] ?? 0);
            if ($inviterId <= 0 || !empty($freshUser['invite_rewarded_at'])) {
                return;
            }
            if ($this->roleRewardedBefore($roleId)) {
                return;
            }
            if ($inviterId === $userId) {
                $rewardMessage = $this->t('api.profile.self_invite_ignored');
                return;
            }
            $rewardIpAddress = trim($ipAddress) ?: (string)($freshUser['invite_registered_ip'] ?? '');
            if ($this->todayInviteRewardCount($inviterId) >= $this->inviteDailyLimit()) {
                $rewardMessage = $this->t('api.profile.invite_daily_limit_reached');
                return;
            }
            if ($rewardIpAddress !== '' && $this->todayInviteRewardCountByIp($inviterId, $rewardIpAddress) >= $this->inviteSameIpDailyLimit()) {
                $rewardMessage = $this->t('api.profile.invite_ip_risk_blocked');
                return;
            }

            $inviter = $this->users->findActiveById($inviterId);
            if (!$inviter) {
                $rewardMessage = $this->t('api.profile.inviter_not_found');
                return;
            }

            Db::table('ga_users')
                ->where('id', $inviterId)
                ->update([
                    'balance' => Db::raw('balance + 1'),
                    'updated_at' => $now,
                ]);

            $balanceAfter = (string)(Db::table('ga_users')->where('id', $inviterId)->value('balance') ?? '0.00');
            Db::table('ga_user_point_transactions')->insert([
                'user_id' => $inviterId,
                'type' => 'invite_reward',
                'amount' => self::INVITE_REWARD_AMOUNT,
                'balance_after' => $balanceAfter,
                'description' => $this->t('api.profile.invite_reward_description', [
                    'account' => (string)$freshUser['account'],
                    'role' => $roleId,
                ]),
                'related_user_id' => $userId,
                'related_role_id' => $roleId,
                'ip_address' => $rewardIpAddress,
                'created_at' => $now,
            ]);

            Db::table('ga_users')
                ->where('id', $userId)
                ->update([
                    'invite_rewarded_at' => $now,
                    'updated_at' => $now,
                ]);
            $rewarded = true;
        });

        return ApiResponse::success([
            'role_binding' => [
                'role_id' => $roleId,
                'bound_at' => $now,
            ],
            'rewarded' => $rewarded,
            'reward_message' => $rewardMessage,
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

    private function roleRewardedBefore(string $roleId): bool
    {
        return Db::table('ga_user_point_transactions')
            ->where('type', 'invite_reward')
            ->where('related_role_id', $roleId)
            ->exists();
    }

    private function todayInviteRewardCount(int $inviterId): int
    {
        return Db::table('ga_user_point_transactions')
            ->where('user_id', $inviterId)
            ->where('type', 'invite_reward')
            ->where('created_at', '>=', date('Y-m-d 00:00:00'))
            ->count();
    }

    private function todayInviteRewardCountByIp(int $inviterId, string $ipAddress): int
    {
        return Db::table('ga_user_point_transactions')
            ->where('user_id', $inviterId)
            ->where('type', 'invite_reward')
            ->where('ip_address', trim($ipAddress))
            ->where('created_at', '>=', date('Y-m-d 00:00:00'))
            ->count();
    }

    private function inviteDailyLimit(): int
    {
        $limit = (int)$this->settings->get('invite_daily_limit', '50');
        return max(1, $limit);
    }

    private function inviteSameIpDailyLimit(): int
    {
        $limit = (int)$this->settings->get('invite_same_ip_daily_limit', '3');
        return max(1, $limit);
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
