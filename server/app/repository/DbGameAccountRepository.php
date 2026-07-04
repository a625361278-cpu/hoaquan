<?php

namespace app\repository;

use support\Db;

class DbGameAccountRepository implements GameAccountRepositoryInterface
{
    public function listByUserId(int $userId): array
    {
        return Db::table('ga_game_accounts')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get()
            ->map(static fn ($row): array => (array)$row)
            ->all();
    }

    public function findByUserId(int $userId, int $accountId): ?array
    {
        $row = Db::table('ga_game_accounts')
            ->where('user_id', $userId)
            ->where('id', $accountId)
            ->first();

        return $row ? (array)$row : null;
    }

    public function findById(int $accountId): ?array
    {
        $row = Db::table('ga_game_accounts')
            ->where('id', $accountId)
            ->first();

        return $row ? (array)$row : null;
    }

    public function listByStatuses(array $statuses): array
    {
        if ($statuses === []) {
            return [];
        }

        return Db::table('ga_game_accounts')
            ->whereIn('status', $statuses)
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => (array)$row)
            ->all();
    }

    public function createLocalPreview(int $userId, array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $id = Db::table('ga_game_accounts')->insertGetId([
            'user_id' => $userId,
            'display_name' => $data['display_name'],
            'game_username' => $data['game_username'],
            'channel_code' => $data['channel_code'],
            'game_password_cipher' => $data['game_password_cipher'],
            'server_id' => $data['server_id'],
            'server_name' => $data['server_name'],
            'status' => 'local_preview',
            'sync_status' => 'local_unsynced',
            'third_party_account_id' => '',
            'log_session_id' => '',
            'remark' => $data['remark'],
            'config_json' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByUserId($userId, (int)$id) ?? [];
    }

    public function saveLocalConfig(int $userId, int $accountId, array $config, string $syncStatus): array
    {
        Db::table('ga_game_accounts')
            ->where('user_id', $userId)
            ->where('id', $accountId)
            ->update([
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sync_status' => $syncStatus,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->findByUserId($userId, $accountId) ?? [];
    }

    public function updateCredentials(int $userId, int $accountId, string $encryptedPassword): array
    {
        Db::table('ga_game_accounts')
            ->where('user_id', $userId)
            ->where('id', $accountId)
            ->update([
                'game_password_cipher' => $encryptedPassword,
                'sync_status' => 'local_unsynced',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->findByUserId($userId, $accountId) ?? [];
    }

    public function updateRuntimeState(int $userId, int $accountId, array $data): array
    {
        $allowed = [
            'status',
            'sync_status',
            'server_id',
            'server_name',
            'display_name',
            'third_party_account_id',
            'log_session_id',
            'expire_time',
            'remark',
        ];
        $update = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $data)) {
                $update[$column] = $data[$column];
            }
        }

        if ($update !== []) {
            $update['updated_at'] = date('Y-m-d H:i:s');
            Db::table('ga_game_accounts')
                ->where('user_id', $userId)
                ->where('id', $accountId)
                ->update($update);
        }

        return $this->findByUserId($userId, $accountId) ?? [];
    }

    public function deleteForUser(int $userId, int $accountId): void
    {
        Db::table('ga_game_account_logs')->where('game_account_id', $accountId)->delete();
        Db::table('ga_game_accounts')
            ->where('user_id', $userId)
            ->where('id', $accountId)
            ->delete();
    }

    public function appendLogLines(int $accountId, array $lines, int $maxLines): void
    {
        if ($lines === []) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $lastLine = (int)(Db::table('ga_game_account_logs')
            ->where('game_account_id', $accountId)
            ->max('line_no') ?? 0);

        $rows = [];
        foreach ($lines as $line) {
            $rows[] = [
                'game_account_id' => $accountId,
                'line_no' => ++$lastLine,
                'message' => $line,
                'created_at' => $now,
            ];
        }
        Db::table('ga_game_account_logs')->insert($rows);

        $overflow = $this->countLogLines($accountId) - $maxLines;
        if ($overflow > 0) {
            $ids = Db::table('ga_game_account_logs')
                ->where('game_account_id', $accountId)
                ->orderBy('line_no')
                ->limit($overflow)
                ->pluck('id')
                ->all();
            if ($ids !== []) {
                Db::table('ga_game_account_logs')->whereIn('id', $ids)->delete();
            }
        }
    }

    public function listLogLines(int $accountId, int $afterLine, int $limit): array
    {
        return Db::table('ga_game_account_logs')
            ->where('game_account_id', $accountId)
            ->where('line_no', '>', $afterLine)
            ->orderBy('line_no')
            ->limit($limit)
            ->get()
            ->map(static fn ($row): array => (array)$row)
            ->all();
    }

    public function countLogLines(int $accountId): int
    {
        return Db::table('ga_game_account_logs')->where('game_account_id', $accountId)->count();
    }

    public function clearLogLines(int $accountId): void
    {
        Db::table('ga_game_account_logs')->where('game_account_id', $accountId)->delete();
    }
}
