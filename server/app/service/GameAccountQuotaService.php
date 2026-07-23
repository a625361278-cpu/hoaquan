<?php

namespace app\service;

use app\exception\ApiException;
use app\support\I18n;
use support\Db;

class GameAccountQuotaService
{
    public const BASE_COST_POINTS = 10;
    public const BASE_DAYS = 11;
    public const EXTRA_BONUS_STEP_POINTS = 10;

    public function __construct(
        private mixed $nowProvider = null,
        private string $locale = I18n::DEFAULT_LOCALE
    )
    {
        $this->nowProvider ??= static fn (): int => time();
        $this->locale = I18n::normalizeLocale($this->locale);
    }

    public function extendAccount(int $userId, int $accountId, int $extraPoints = 0, bool $packageSelected = true): array
    {
        if ($extraPoints < 0) {
            throw new ApiException($this->t('api.game.quota_extra_invalid'), 422);
        }

        $baseCostPoints = $packageSelected ? self::BASE_COST_POINTS : 0;
        $baseDays = $packageSelected ? self::BASE_DAYS : 0;
        $bonusDays = intdiv($extraPoints, self::EXTRA_BONUS_STEP_POINTS);
        $costPoints = $baseCostPoints + $extraPoints;
        if ($costPoints < 1) {
            throw new ApiException($this->t('api.game.quota_minimum_required'), 422);
        }
        $addDays = $baseDays + $extraPoints + $bonusDays;
        $now = $this->now();
        $newExpireTime = '';
        $balanceAfter = '';
        $account = [];

        Db::connection()->transaction(function () use ($userId, $accountId, $costPoints, $addDays, $now, &$newExpireTime, &$balanceAfter, &$account) {
            $user = Db::table('ga_users')
                ->where('id', $userId)
                ->where('status', 1)
                ->lockForUpdate()
                ->first();
            if (!$user) {
                throw new ApiException($this->t('api.auth.login_expired'), 401);
            }

            $accountRow = Db::table('ga_game_accounts')
                ->where('id', $accountId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            if (!$accountRow) {
                throw new ApiException($this->t('api.game.account_not_found'), 404);
            }

            $balanceCents = $this->decimalToCents((string)($user->balance ?? '0.00'));
            $costCents = $costPoints * 100;
            if ($balanceCents < $costCents) {
                throw new ApiException($this->t('api.game.quota_balance_insufficient'), 422);
            }

            $baseTimestamp = $this->expiryBaseTimestamp($accountRow->expire_time ?? null, $now);
            $newExpireTime = date('Y-m-d H:i:s', $baseTimestamp + $addDays * 86400);
            $balanceAfter = $this->formatCents($balanceCents - $costCents);
            $nowText = date('Y-m-d H:i:s', $now);

            Db::table('ga_users')
                ->where('id', $userId)
                ->update([
                    'balance' => $balanceAfter,
                    'updated_at' => $nowText,
                ]);

            Db::table('ga_game_accounts')
                ->where('id', $accountId)
                ->where('user_id', $userId)
                ->update([
                    'expire_time' => $newExpireTime,
                    'updated_at' => $nowText,
                ]);

            Db::table('ga_user_point_transactions')->insert([
                'user_id' => $userId,
                'type' => 'quota_consume',
                'amount' => $this->formatCents(-$costCents),
                'balance_after' => $balanceAfter,
                'description' => $this->t('api.game.quota_consume_description', [
                    'account' => (string)($accountRow->display_name ?? $accountRow->game_username ?? $accountId),
                    'days' => (string)$addDays,
                ]),
                'related_user_id' => null,
                'related_role_id' => (string)$accountId,
                'ip_address' => '',
                'created_at' => $nowText,
            ]);

            $account = (array)Db::table('ga_game_accounts')->where('id', $accountId)->first();
        });

        return [
            'account' => $account,
            'balance' => $balanceAfter,
            'cost_points' => $costPoints,
            'add_days' => $addDays,
            'bonus_days' => $bonusDays,
            'package_selected' => $packageSelected,
            'expire_time' => $newExpireTime,
        ];
    }

    private function expiryBaseTimestamp(mixed $expireTime, int $now): int
    {
        $expireText = trim((string)$expireTime);
        if ($expireText === '') {
            return $now;
        }

        $expireTimestamp = strtotime($expireText);
        if ($expireTimestamp === false) {
            throw new \RuntimeException('游戏账号到期时间格式异常：' . $expireText);
        }

        return max($now, $expireTimestamp);
    }

    private function decimalToCents(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new \RuntimeException('用户配额余额格式异常：' . $value);
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$yuan, $cents] = array_pad(explode('.', $value, 2), 2, '0');
        $amount = ((int)$yuan) * 100 + (int)str_pad(substr($cents, 0, 2), 2, '0');
        return $negative ? -$amount : $amount;
    }

    private function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    private function now(): int
    {
        return (int)($this->nowProvider)();
    }

    private function t(string $key, array $parameters = []): string
    {
        return I18n::t($key, $parameters, $this->locale);
    }
}
