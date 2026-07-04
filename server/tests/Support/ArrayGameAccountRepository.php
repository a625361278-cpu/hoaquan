<?php

namespace tests\Support;

use app\repository\GameAccountRepositoryInterface;

class ArrayGameAccountRepository implements GameAccountRepositoryInterface
{
    private int $nextId = 1;
    private array $logs = [];

    public function __construct(private array $accounts)
    {
        foreach ($this->accounts as $account) {
            $this->nextId = max($this->nextId, (int)$account['id'] + 1);
        }
    }

    public function listByUserId(int $userId): array
    {
        return array_values(array_filter(
            $this->accounts,
            static fn (array $account): bool => (int)$account['user_id'] === $userId
        ));
    }

    public function findByUserId(int $userId, int $accountId): ?array
    {
        foreach ($this->accounts as $account) {
            if ((int)$account['user_id'] === $userId && (int)$account['id'] === $accountId) {
                return $account;
            }
        }

        return null;
    }

    public function findById(int $accountId): ?array
    {
        foreach ($this->accounts as $account) {
            if ((int)$account['id'] === $accountId) {
                return $account;
            }
        }

        return null;
    }

    public function listByStatuses(array $statuses): array
    {
        return array_values(array_filter(
            $this->accounts,
            static fn (array $account): bool => in_array((string)($account['status'] ?? ''), $statuses, true)
        ));
    }

    public function createLocalPreview(int $userId, array $data): array
    {
        $account = [
            'id' => $this->nextId++,
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
            'created_at' => '2026-07-02 00:00:00',
        ];

        $this->accounts[] = $account;
        return $account;
    }

    public function saveLocalConfig(int $userId, int $accountId, array $config, string $syncStatus): array
    {
        foreach ($this->accounts as $index => $account) {
            if ((int)$account['user_id'] === $userId && (int)$account['id'] === $accountId) {
                $this->accounts[$index]['config_json'] = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->accounts[$index]['sync_status'] = $syncStatus;
                return $this->accounts[$index];
            }
        }

        throw new \RuntimeException('Account not found in test repository');
    }

    public function updateCredentials(int $userId, int $accountId, string $encryptedPassword): array
    {
        foreach ($this->accounts as $index => $account) {
            if ((int)$account['user_id'] === $userId && (int)$account['id'] === $accountId) {
                $this->accounts[$index]['game_password_cipher'] = $encryptedPassword;
                $this->accounts[$index]['sync_status'] = 'local_unsynced';
                return $this->accounts[$index];
            }
        }

        throw new \RuntimeException('Account not found in test repository');
    }

    public function updateRuntimeState(int $userId, int $accountId, array $data): array
    {
        foreach ($this->accounts as $index => $account) {
            if ((int)$account['user_id'] === $userId && (int)$account['id'] === $accountId) {
                foreach ($data as $key => $value) {
                    $this->accounts[$index][$key] = $value;
                }
                return $this->accounts[$index];
            }
        }

        throw new \RuntimeException('Account not found in test repository');
    }

    public function deleteForUser(int $userId, int $accountId): void
    {
        $this->accounts = array_values(array_filter(
            $this->accounts,
            static fn (array $account): bool => !((int)$account['user_id'] === $userId && (int)$account['id'] === $accountId)
        ));
        unset($this->logs[$accountId]);
    }

    public function appendLogLines(int $accountId, array $lines, int $maxLines): void
    {
        $this->logs[$accountId] ??= [];
        $lastLine = 0;
        foreach ($this->logs[$accountId] as $row) {
            $lastLine = max($lastLine, (int)$row['line_no']);
        }
        foreach ($lines as $line) {
            $this->logs[$accountId][] = [
                'id' => count($this->logs[$accountId]) + 1,
                'game_account_id' => $accountId,
                'line_no' => ++$lastLine,
                'message' => $line,
            ];
        }
        if (count($this->logs[$accountId]) > $maxLines) {
            $this->logs[$accountId] = array_slice($this->logs[$accountId], -$maxLines);
        }
    }

    public function listLogLines(int $accountId, int $afterLine, int $limit): array
    {
        $rows = array_values(array_filter(
            $this->logs[$accountId] ?? [],
            static fn (array $row): bool => (int)$row['line_no'] > $afterLine
        ));
        return array_slice($rows, 0, $limit);
    }

    public function countLogLines(int $accountId): int
    {
        return count($this->logs[$accountId] ?? []);
    }

    public function clearLogLines(int $accountId): void
    {
        $this->logs[$accountId] = [];
    }
}
