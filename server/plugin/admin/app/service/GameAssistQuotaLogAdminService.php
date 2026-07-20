<?php

namespace plugin\admin\app\service;

use app\support\I18n;
use RuntimeException;
use support\Db;
use support\Log;

class GameAssistQuotaLogAdminService
{
    private string $locale;

    public function __construct(?string $locale = null)
    {
        $this->locale = $locale ?: I18n::DEFAULT_LOCALE;
    }

    public function grantRecords(array $filters): array
    {
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table('ga_admin_operation_logs as log')
            ->leftJoin('wa_admins as admin', 'admin.id', '=', 'log.admin_id')
            ->leftJoin('ga_users as user', 'user.id', '=', 'log.target_id')
            ->where('log.action', 'gameassist_user.grant_quota');

        $this->applyIdFilter($query, 'log.admin_id', $filters['admin_id'] ?? null);
        $this->applyLikeFilter($query, 'admin.username', $filters['admin_account'] ?? null, 64);
        $this->applyIdFilter($query, 'log.target_id', $filters['user_id'] ?? null);
        $this->applyLikeFilter($query, 'user.account', $filters['user_account'] ?? null, 64);
        $this->applyDateRange($query, 'log.created_at', $filters['created_at'] ?? null);

        $count = (clone $query)->count('log.id');
        $rows = $query
            ->select([
                'log.id',
                'log.admin_id',
                'log.target_id as user_id',
                'log.payload',
                'log.created_at',
                'admin.username as admin_account',
                'admin.nickname as admin_nickname',
                'user.account as user_account',
                'user.nickname as user_nickname',
            ])
            ->orderBy($this->sortColumn($filters, 'log'), $this->sortOrder($filters))
            ->forPage($page, $limit)
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $data[] = $this->formatGrantRecord($row);
        }

        return ['count' => $count, 'data' => $data];
    }

    public function consumeRecords(array $filters): array
    {
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table('ga_user_point_transactions as transaction')
            ->leftJoin('ga_users as user', 'user.id', '=', 'transaction.user_id')
            ->leftJoin('ga_game_accounts as game', 'game.id', '=', 'transaction.related_role_id');

        $this->applyIdFilter($query, 'transaction.user_id', $filters['user_id'] ?? null);
        $this->applyLikeFilter($query, 'user.account', $filters['user_account'] ?? null, 64);
        $this->applyIdFilter($query, 'transaction.related_role_id', $filters['game_account_id'] ?? null);
        $this->applyTransactionTypeFilter($query, $filters['type'] ?? null);
        $this->applyGameAccountFilter($query, $filters['game_account'] ?? null);
        $this->applyDateRange($query, 'transaction.created_at', $filters['created_at'] ?? null);

        $count = (clone $query)->count('transaction.id');
        $rows = $query
            ->select([
                'transaction.id',
                'transaction.user_id',
                'transaction.type',
                'transaction.amount',
                'transaction.balance_after',
                'transaction.description',
                'transaction.related_role_id as game_account_id',
                'transaction.related_user_id',
                'transaction.related_payment_order_id',
                'transaction.created_at',
                'user.account as user_account',
                'user.nickname as user_nickname',
                'game.game_username',
                'game.display_name as game_display_name',
                'game.server_name',
            ])
            ->orderBy($this->sortColumn($filters, 'transaction'), $this->sortOrder($filters))
            ->forPage($page, $limit)
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $data[] = $this->formatConsumeRecord($row);
        }

        return ['count' => $count, 'data' => $data];
    }

    private function formatGrantRecord(object $row): array
    {
        $payload = json_decode((string)$row->payload, true);
        $payloadValid = is_array($payload)
            && isset($payload['points'])
            && filter_var($payload['points'], FILTER_VALIDATE_INT) !== false
            && (int)$payload['points'] > 0
            && isset($payload['balance_after'])
            && preg_match('/^-?\d+(?:\.\d{1,2})?$/', (string)$payload['balance_after']) === 1
            && isset($payload['remark'])
            && is_string($payload['remark']);

        if (!$payloadValid) {
            Log::error('Admin quota operation log payload invalid', ['log_id' => (int)$row->id]);
        }

        return [
            'id' => (int)$row->id,
            'admin_id' => $row->admin_id === null ? null : (int)$row->admin_id,
            'admin_account' => $row->admin_account === null ? '' : (string)$row->admin_account,
            'admin_nickname' => $row->admin_nickname === null ? '' : (string)$row->admin_nickname,
            'admin_exists' => $row->admin_account !== null,
            'user_id' => ctype_digit((string)$row->user_id) ? (int)$row->user_id : (string)$row->user_id,
            'user_account' => $row->user_account === null ? '' : (string)$row->user_account,
            'user_nickname' => $row->user_nickname === null ? '' : (string)$row->user_nickname,
            'user_exists' => $row->user_account !== null,
            'points' => $payloadValid ? (int)$payload['points'] : null,
            'balance_after' => $payloadValid ? (string)$payload['balance_after'] : null,
            'remark' => $payloadValid ? (string)$payload['remark'] : $this->t('admin.quota_logs.invalid_content'),
            'payload_invalid' => !$payloadValid,
            'created_at' => (string)$row->created_at,
        ];
    }

    private function formatConsumeRecord(object $row): array
    {
        $amount = (string)$row->amount;
        $amountValid = preg_match('/^-?\d+(?:\.\d{1,2})?$/', $amount) === 1 && (float)$amount !== 0.0;
        if (!$amountValid) {
            Log::error('User quota transaction amount invalid', ['transaction_id' => (int)$row->id]);
        }

        $accountIdText = (string)$row->game_account_id;
        $isIncrease = $amountValid && (float)$amount > 0;
        $isConsume = $amountValid && (float)$amount < 0;
        return [
            'id' => (int)$row->id,
            'user_id' => (int)$row->user_id,
            'user_account' => $row->user_account === null ? '' : (string)$row->user_account,
            'user_nickname' => $row->user_nickname === null ? '' : (string)$row->user_nickname,
            'user_exists' => $row->user_account !== null,
            'type' => (string)$row->type,
            'type_label' => $this->transactionTypeLabel((string)$row->type),
            'game_account_id' => ctype_digit($accountIdText) ? (int)$accountIdText : $accountIdText,
            'game_account_has_reference' => $accountIdText !== '',
            'game_username' => $row->game_username === null ? '' : (string)$row->game_username,
            'game_display_name' => $row->game_display_name === null ? '' : (string)$row->game_display_name,
            'server_name' => $row->server_name === null ? '' : (string)$row->server_name,
            'game_account_exists' => $row->game_username !== null,
            'game_account_relation_invalid' => !ctype_digit($accountIdText),
            'changed_points' => $amountValid ? $amount : null,
            'increased_points' => $isIncrease ? $amount : null,
            'consumed_points' => $isConsume ? substr($amount, 1) : null,
            'balance_after' => (string)$row->balance_after,
            'description' => (string)$row->description,
            'related_user_id' => $row->related_user_id === null ? null : (int)$row->related_user_id,
            'related_payment_order_id' => $row->related_payment_order_id === null ? null : (int)$row->related_payment_order_id,
            'amount_invalid' => !$amountValid,
            'created_at' => (string)$row->created_at,
        ];
    }

    private function pagination(array $filters): array
    {
        $page = $this->positiveInteger($filters['page'] ?? 1, 'page');
        $limit = $this->positiveInteger($filters['limit'] ?? 20, 'limit');
        if (!in_array($limit, [20, 50, 100], true)) {
            throw new RuntimeException($this->t('admin.quota_logs.page_size_invalid'));
        }
        return [$page, $limit];
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        $text = trim((string)$value);
        if ($text === '' || !ctype_digit($text) || (int)$text <= 0) {
            throw new RuntimeException($this->t('admin.quota_logs.filter_invalid', ['field' => $field]));
        }
        return (int)$text;
    }

    private function applyIdFilter(object $query, string $column, mixed $value): void
    {
        if ($value === null || trim((string)$value) === '') {
            return;
        }
        $query->where($column, (string)$this->positiveInteger($value, $column));
    }

    private function applyLikeFilter(object $query, string $column, mixed $value, int $maxLength): void
    {
        $text = trim((string)$value);
        if ($text === '') {
            return;
        }
        if (mb_strlen($text) > $maxLength) {
            throw new RuntimeException($this->t('admin.quota_logs.filter_too_long'));
        }
        $query->where($column, 'like', '%' . $text . '%');
    }

    private function applyGameAccountFilter(object $query, mixed $value): void
    {
        $text = trim((string)$value);
        if ($text === '') {
            return;
        }
        if (mb_strlen($text) > 128) {
            throw new RuntimeException($this->t('admin.quota_logs.filter_too_long'));
        }
        $query->where(function ($nested) use ($text) {
            $nested->where('game.game_username', 'like', '%' . $text . '%')
                ->orWhere('game.game_uid', 'like', '%' . $text . '%')
                ->orWhere('game.display_name', 'like', '%' . $text . '%')
                ->orWhere('game.server_name', 'like', '%' . $text . '%')
                ->orWhere('transaction.related_role_id', 'like', '%' . $text . '%');
        });
    }

    private function applyTransactionTypeFilter(object $query, mixed $value): void
    {
        $text = trim((string)$value);
        if ($text === '') {
            return;
        }
        if (!preg_match('/^[a-z0-9_]{1,32}$/', $text)) {
            throw new RuntimeException($this->t('admin.quota_logs.transaction_type_invalid'));
        }
        $query->where('transaction.type', $text);
    }

    private function transactionTypeLabel(string $type): string
    {
        return match ($type) {
            'registration_reward' => $this->t('admin.quota_logs.type_registration_reward'),
            'admin_grant' => $this->t('admin.quota_logs.type_admin_grant'),
            'quota_consume' => $this->t('admin.quota_logs.type_quota_consume'),
            'invite_reward' => $this->t('admin.quota_logs.type_invite_reward'),
            'recharge' => $this->t('admin.quota_logs.type_recharge'),
            default => $type,
        };
    }

    private function applyDateRange(object $query, string $column, mixed $value): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }
        if (!is_array($value) || count($value) !== 2) {
            throw new RuntimeException($this->t('admin.quota_logs.date_range_invalid'));
        }
        $start = trim((string)($value[0] ?? ''));
        $end = trim((string)($value[1] ?? ''));
        if ($start === '' && $end === '') {
            return;
        }
        if ($start === '' || $end === '' || !$this->validDateTime($start) || !$this->validDateTime($end) || $start > $end) {
            throw new RuntimeException($this->t('admin.quota_logs.date_range_invalid'));
        }
        $query->whereBetween($column, [$start, $end]);
    }

    private function validDateTime(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }

    private function sortColumn(array $filters, string $alias): string
    {
        $field = (string)($filters['field'] ?? 'id');
        if (!in_array($field, ['id', 'created_at'], true)) {
            throw new RuntimeException($this->t('admin.quota_logs.sort_invalid'));
        }
        return $alias . '.' . $field;
    }

    private function sortOrder(array $filters): string
    {
        $order = strtolower((string)($filters['order'] ?? 'desc'));
        if (!in_array($order, ['asc', 'desc'], true)) {
            throw new RuntimeException($this->t('admin.quota_logs.sort_invalid'));
        }
        return $order;
    }

    private function t(string $key, array $parameters = []): string
    {
        return I18n::t($key, $parameters, $this->locale);
    }
}
