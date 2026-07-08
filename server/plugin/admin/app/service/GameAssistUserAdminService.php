<?php

namespace plugin\admin\app\service;

use app\support\I18n;
use RuntimeException;
use support\Db;

class GameAssistUserAdminService
{
    public function __construct(private string $locale = I18n::DEFAULT_LOCALE)
    {
        $this->locale = I18n::normalizeLocale($this->locale);
    }

    public function sanitizeRows(array $rows): array
    {
        return array_map(static function ($row): array {
            if (is_array($row)) {
                $item = $row;
            } elseif (is_object($row) && method_exists($row, 'toArray')) {
                $item = $row->toArray();
            } else {
                $item = (array)$row;
            }
            unset($item['password_hash']);
            return $item;
        }, $rows);
    }

    public function filterStatusUpdate(array $data): array
    {
        if (!array_key_exists('status', $data)) {
            throw new RuntimeException($this->t('admin.gameassist.status_empty'));
        }

        if (!in_array((string)$data['status'], ['0', '1'], true)) {
            throw new RuntimeException($this->t('admin.gameassist.status_invalid'));
        }

        return ['status' => (int)$data['status']];
    }

    public function buildPasswordHash(string $password): string
    {
        if (mb_strlen($password) < 6) {
            throw new RuntimeException($this->t('admin.gameassist.password_min_length'));
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function grantQuota(int $userId, int $points, string $remark = '', ?int $adminId = null): array
    {
        if ($points <= 0) {
            throw new RuntimeException($this->t('admin.gameassist.quota_positive'));
        }

        $balanceAfter = '';
        Db::connection()->transaction(function () use ($userId, $points, $remark, $adminId, &$balanceAfter) {
            $user = Db::table('ga_users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->first();
            if (!$user) {
                throw new RuntimeException($this->t('admin.gameassist.user_not_found'));
            }

            $balanceAfter = $this->formatCents($this->decimalToCents((string)($user->balance ?? '0.00')) + $points * 100);
            $now = date('Y-m-d H:i:s');
            $description = trim($remark) !== '' ? trim($remark) : $this->t('admin.gameassist.quota_default_remark');

            Db::table('ga_users')
                ->where('id', $userId)
                ->update([
                    'balance' => $balanceAfter,
                    'updated_at' => $now,
                ]);

            Db::table('ga_user_point_transactions')->insert([
                'user_id' => $userId,
                'type' => 'admin_grant',
                'amount' => $this->formatCents($points * 100),
                'balance_after' => $balanceAfter,
                'description' => $description,
                'related_user_id' => null,
                'related_role_id' => '',
                'ip_address' => '',
                'created_at' => $now,
            ]);

            Db::table('ga_admin_operation_logs')->insert([
                'admin_id' => $adminId,
                'action' => 'gameassist_user.grant_quota',
                'target_type' => 'ga_users',
                'target_id' => (string)$userId,
                'payload' => json_encode([
                    'points' => $points,
                    'balance_after' => $balanceAfter,
                    'remark' => $description,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
            ]);
        });

        return [
            'id' => $userId,
            'balance' => $balanceAfter,
        ];
    }

    private function decimalToCents(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new RuntimeException('用户配额余额格式异常：' . $value);
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

    private function t(string $key): string
    {
        return I18n::t($key, [], $this->locale);
    }
}
