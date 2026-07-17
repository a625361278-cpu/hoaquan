<?php

namespace app\service;

use app\support\I18n;
use RuntimeException;
use support\Db;

class InviteRewardService
{
    private const REWARD_AMOUNT = '1.00';

    public function __construct(
        private ?SystemSettingService $settings = null,
        private string $locale = I18n::DEFAULT_LOCALE
    ) {
        $this->settings ??= new SystemSettingService();
        $this->locale = I18n::normalizeLocale($this->locale);
    }

    public function tryGrantForAccountLevel(int $accountId, mixed $reportedLevel): array
    {
        $minimumLevel = $this->settings->inviteRewardMinRoleLevel();
        $normalized = $this->normalizeLevel($reportedLevel);
        if ($normalized['state'] !== 'valid') {
            return $this->result(false, $normalized['state'], null, $minimumLevel);
        }

        $level = $normalized['level'];
        if ($level < $minimumLevel) {
            return $this->result(false, 'below_minimum_level', $level, $minimumLevel);
        }

        return Db::connection()->transaction(function () use ($accountId, $level, $minimumLevel): array {
            $account = Db::table('ga_game_accounts')
                ->where('id', $accountId)
                ->lockForUpdate()
                ->first();
            if (!$account) {
                return $this->result(false, 'account_not_found', $level, $minimumLevel);
            }
            if ((string)$account->status !== GameAccountService::RUNNING_STATUS
                || (int)$account->desired_running !== 1) {
                return $this->result(false, 'account_not_running', $level, $minimumLevel);
            }

            $roleId = trim((string)($account->third_party_account_id ?? ''));
            if ($roleId === '') {
                return $this->result(false, 'account_role_missing', $level, $minimumLevel);
            }

            $inviteeId = (int)$account->user_id;
            $preliminaryInvitee = Db::table('ga_users')->where('id', $inviteeId)->first();
            if (!$preliminaryInvitee) {
                return $this->result(false, 'invitee_inactive', $level, $minimumLevel);
            }
            $preliminaryInviterId = (int)($preliminaryInvitee->invited_by_user_id ?? 0);
            $userIds = [$inviteeId];
            if ($preliminaryInviterId > 0 && $preliminaryInviterId !== $inviteeId) {
                $userIds[] = $preliminaryInviterId;
            }
            sort($userIds, SORT_NUMERIC);
            $lockedUsers = Db::table('ga_users')
                ->whereIn('id', $userIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $invitee = $lockedUsers->get($inviteeId);
            if (!$invitee || (int)$invitee->status !== 1) {
                return $this->result(false, 'invitee_inactive', $level, $minimumLevel);
            }
            if (trim((string)($invitee->bound_role_id ?? '')) !== $roleId
                || empty($invitee->role_bound_at)) {
                return $this->result(false, 'bound_role_mismatch', $level, $minimumLevel);
            }

            $inviterId = (int)($invitee->invited_by_user_id ?? 0);
            if ($inviterId !== $preliminaryInviterId) {
                throw new RuntimeException('邀请关系在奖励事务锁定期间发生变化，user_id=' . $inviteeId);
            }
            if ($inviterId <= 0) {
                return $this->result(false, 'invitation_missing', $level, $minimumLevel);
            }
            if ($inviterId === (int)$invitee->id) {
                return $this->result(false, 'self_invitation', $level, $minimumLevel);
            }
            if (!empty($invitee->invite_rewarded_at)) {
                return $this->result(false, 'already_rewarded', $level, $minimumLevel);
            }

            $existingInviteeReward = Db::table('ga_user_point_transactions')
                ->where('type', 'invite_reward')
                ->where('related_user_id', (int)$invitee->id)
                ->first();
            if ($existingInviteeReward) {
                throw new RuntimeException('邀请奖励状态不一致：奖励流水已存在但用户奖励时间为空，user_id=' . (int)$invitee->id);
            }
            if (Db::table('ga_user_point_transactions')
                ->where('type', 'invite_reward')
                ->where('related_role_id', $roleId)
                ->exists()) {
                return $this->result(false, 'role_already_rewarded', $level, $minimumLevel);
            }

            $inviter = $lockedUsers->get($inviterId);
            if (!$inviter || (int)$inviter->status !== 1) {
                return $this->result(false, 'inviter_inactive', $level, $minimumLevel);
            }

            $now = date('Y-m-d H:i:s');
            $updatedInviter = Db::table('ga_users')
                ->where('id', $inviterId)
                ->where('status', 1)
                ->update([
                    'balance' => Db::raw('balance + 1'),
                    'updated_at' => $now,
                ]);
            if ($updatedInviter !== 1) {
                throw new RuntimeException('邀请人余额更新失败，user_id=' . $inviterId);
            }

            $balanceAfter = Db::table('ga_users')->where('id', $inviterId)->value('balance');
            if ($balanceAfter === null) {
                throw new RuntimeException('邀请人余额读取失败，user_id=' . $inviterId);
            }

            Db::table('ga_user_point_transactions')->insert([
                'user_id' => $inviterId,
                'type' => 'invite_reward',
                'amount' => self::REWARD_AMOUNT,
                'balance_after' => (string)$balanceAfter,
                'description' => I18n::t('api.profile.invite_reward_description', [
                    'account' => (string)$invitee->account,
                    'role' => $roleId,
                ], $this->locale),
                'related_user_id' => (int)$invitee->id,
                'related_role_id' => $roleId,
                'ip_address' => trim((string)($invitee->invite_registered_ip ?? '')),
                'created_at' => $now,
            ]);

            $marked = Db::table('ga_users')
                ->where('id', (int)$invitee->id)
                ->whereNull('invite_rewarded_at')
                ->update([
                    'invite_rewarded_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($marked !== 1) {
                throw new RuntimeException('邀请奖励时间标记失败，user_id=' . (int)$invitee->id);
            }

            return $this->result(true, 'rewarded', $level, $minimumLevel);
        });
    }

    private function normalizeLevel(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['state' => 'level_missing', 'level' => null];
        }
        if (is_int($value)) {
            return $value >= 0
                ? ['state' => 'valid', 'level' => $value]
                : ['state' => 'level_invalid', 'level' => null];
        }
        if (is_float($value)) {
            return is_finite($value) && $value >= 0 && $value <= PHP_INT_MAX && floor($value) === $value
                ? ['state' => 'valid', 'level' => (int)$value]
                : ['state' => 'level_invalid', 'level' => null];
        }
        if (!is_string($value) || !preg_match('/^\d+$/', $value)) {
            return ['state' => 'level_invalid', 'level' => null];
        }

        $digits = ltrim($value, '0');
        $digits = $digits === '' ? '0' : $digits;
        $maximum = (string)PHP_INT_MAX;
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            return ['state' => 'level_invalid', 'level' => null];
        }
        return ['state' => 'valid', 'level' => (int)$digits];
    }

    private function result(bool $rewarded, string $reason, ?int $level, int $minimumLevel): array
    {
        return [
            'rewarded' => $rewarded,
            'reason' => $reason,
            'level' => $level,
            'min_level' => $minimumLevel,
        ];
    }
}
