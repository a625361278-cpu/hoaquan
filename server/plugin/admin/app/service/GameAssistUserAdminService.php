<?php

namespace plugin\admin\app\service;

use app\service\GameAccountLoginMethod;
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

    public function gameAccounts(int $userId, array $filters = []): array
    {
        if ($userId <= 0) {
            throw new RuntimeException($this->t('admin.gameassist.user_id_invalid'));
        }

        $user = Db::table('ga_users')
            ->select(['id', 'account', 'nickname', 'bound_role_id'])
            ->where('id', $userId)
            ->first();
        if (!$user) {
            throw new RuntimeException($this->t('admin.gameassist.user_not_found'));
        }

        [$page, $limit] = $this->gameAccountPagination($filters);
        $query = Db::table('ga_game_accounts')
            ->where('user_id', $userId);

        $this->applyGameAccountIdFilter($query, $filters['game_account_id'] ?? null);
        $this->applyGameAccountLikeFilter($query, $filters['game_account'] ?? null);
        $this->applyLoginMethodFilter($query, $filters['login_method'] ?? null);

        $count = (clone $query)->count('id');
        $rows = $query
            ->select([
                'id',
                'user_id',
                'display_name',
                'game_username',
                'game_uid',
                'channel_code',
                'login_method',
                'server_id',
                'server_name',
                'status',
                'sync_status',
                'third_party_account_id',
                'desired_running',
                'expire_time',
                'remark',
                'created_at',
                'updated_at',
            ])
            ->orderByDesc('id')
            ->forPage($page, $limit)
            ->get();

        $boundRoleId = trim((string)($user->bound_role_id ?? ''));
        $data = [];
        foreach ($rows as $row) {
            $data[] = $this->formatGameAccountRow($row, $boundRoleId);
        }

        return [
            'count' => $count,
            'data' => $data,
            'user' => [
                'id' => (int)$user->id,
                'account' => (string)$user->account,
                'nickname' => (string)$user->nickname,
                'bound_role_id' => $boundRoleId,
            ],
        ];
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

    private function gameAccountPagination(array $filters): array
    {
        $page = $this->positiveInteger($filters['page'] ?? 1, 'page');
        $limit = $this->positiveInteger($filters['limit'] ?? 20, 'limit');
        if (!in_array($limit, [20, 50, 100], true)) {
            throw new RuntimeException($this->t('admin.gameassist.page_size_invalid'));
        }
        return [$page, $limit];
    }

    private function applyGameAccountIdFilter(object $query, mixed $value): void
    {
        $text = trim((string)$value);
        if ($text === '') {
            return;
        }
        $query->where('id', $this->positiveInteger($text, 'game_account_id'));
    }

    private function applyGameAccountLikeFilter(object $query, mixed $value): void
    {
        $text = trim((string)$value);
        if ($text === '') {
            return;
        }
        if (mb_strlen($text) > 128) {
            throw new RuntimeException($this->t('admin.gameassist.filter_too_long'));
        }
        $query->where(function ($nested) use ($text) {
            $nested->where('game_username', 'like', '%' . $text . '%')
                ->orWhere('game_uid', 'like', '%' . $text . '%')
                ->orWhere('display_name', 'like', '%' . $text . '%')
                ->orWhere('third_party_account_id', 'like', '%' . $text . '%')
                ->orWhere('server_name', 'like', '%' . $text . '%');
        });
    }

    private function applyLoginMethodFilter(object $query, mixed $value): void
    {
        $text = trim((string)$value);
        if ($text === '') {
            return;
        }
        if (!ctype_digit($text) || !GameAccountLoginMethod::isSupported((int)$text)) {
            throw new RuntimeException($this->t('admin.gameassist.login_method_invalid'));
        }
        $query->where('login_method', (int)$text);
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        $text = trim((string)$value);
        if ($text === '' || !ctype_digit($text) || (int)$text <= 0) {
            throw new RuntimeException($this->t('admin.gameassist.filter_invalid', ['field' => $field]));
        }
        return (int)$text;
    }

    private function formatGameAccountRow(object $row, string $boundRoleId): array
    {
        $loginMethod = (int)$row->login_method;
        $identity = $loginMethod === GameAccountLoginMethod::ACCOUNT_PASSWORD
            ? (string)$row->game_username
            : (string)$row->game_uid;
        $thirdPartyAccountId = trim((string)$row->third_party_account_id);
        $matchIdentity = $thirdPartyAccountId !== '' ? $thirdPartyAccountId : trim($identity);

        return [
            'id' => (int)$row->id,
            'user_id' => (int)$row->user_id,
            'display_name' => (string)$row->display_name,
            'login_method' => $loginMethod,
            'login_method_label' => $this->loginMethodLabel($loginMethod),
            'login_identity' => $identity,
            'channel_code' => (string)$row->channel_code,
            'server_id' => (string)$row->server_id,
            'server_name' => (string)$row->server_name,
            'status' => (string)$row->status,
            'sync_status' => (string)$row->sync_status,
            'third_party_account_id' => $thirdPartyAccountId,
            'bound_role_id' => $boundRoleId,
            'is_bound' => $boundRoleId !== '' && $matchIdentity === $boundRoleId,
            'desired_running' => (int)$row->desired_running,
            'expire_time' => $row->expire_time === null ? '' : (string)$row->expire_time,
            'remark' => (string)$row->remark,
            'created_at' => (string)$row->created_at,
            'updated_at' => (string)$row->updated_at,
        ];
    }

    private function loginMethodLabel(int $method): string
    {
        return match ($method) {
            GameAccountLoginMethod::ACCOUNT_PASSWORD => $this->t('admin.gameassist.login_method_account_password'),
            GameAccountLoginMethod::FACEBOOK => 'Facebook',
            GameAccountLoginMethod::GOOGLE => 'Google',
            default => $this->t('admin.gameassist.login_method_unknown'),
        };
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

    private function t(string $key, array $parameters = []): string
    {
        return I18n::t($key, $parameters, $this->locale);
    }
}
