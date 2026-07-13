<?php

namespace tests\Support;

use app\repository\GameAccountRepositoryInterface;

class ArrayGameAccountRepository implements GameAccountRepositoryInterface
{
    private int $nextId = 1;
    private array $logs = [];
    private array $normalLogs = [];
    private array $events = [];
    private array $taskStates = [];

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

    public function listAutoRestartCandidates(array $statuses, string $now, int $limit): array
    {
        $rows = array_values(array_filter(
            $this->accounts,
            static function (array $account) use ($statuses, $now): bool {
                if ((int)($account['desired_running'] ?? 0) !== 1) {
                    return false;
                }
                if (!in_array((string)($account['status'] ?? ''), $statuses, true)) {
                    return false;
                }
                $nextAt = $account['auto_restart_next_at'] ?? null;
                return $nextAt === null || $nextAt === '' || (string)$nextAt <= $now;
            }
        ));

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string)($a['auto_restart_next_at'] ?? ''), (string)($b['auto_restart_next_at'] ?? ''))
                ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        return array_slice($rows, 0, max(0, $limit));
    }

    public function listDesiredRunningAccounts(array $statuses, int $afterId, int $limit): array
    {
        $rows = array_values(array_filter(
            $this->accounts,
            static fn (array $account): bool => (int)($account['desired_running'] ?? 0) === 1
                && in_array((string)($account['status'] ?? ''), $statuses, true)
                && (int)($account['id'] ?? 0) > $afterId
        ));

        usort($rows, static fn (array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
        return array_slice($rows, 0, max(0, $limit));
    }

    public function listExpiredActiveAccounts(array $statuses, string $now, int $limit): array
    {
        $rows = array_values(array_filter(
            $this->accounts,
            static fn (array $account): bool => in_array((string)($account['status'] ?? ''), $statuses, true)
                && trim((string)($account['expire_time'] ?? '')) !== ''
                && (string)$account['expire_time'] <= $now
        ));

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string)($a['expire_time'] ?? ''), (string)($b['expire_time'] ?? ''))
                ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        return array_slice($rows, 0, max(0, $limit));
    }

    public function createLocalPreviewWithinLimit(int $userId, array $data, int $maxAccounts): ?array
    {
        $currentCount = count(array_filter(
            $this->accounts,
            static fn (array $account): bool => (int)($account['user_id'] ?? 0) === $userId
        ));
        if ($currentCount >= $maxAccounts) {
            return null;
        }

        $account = [
            'id' => $this->nextId++,
            'user_id' => $userId,
            'display_name' => $data['display_name'],
            'game_username' => $data['game_username'],
            'game_uid' => $data['game_uid'],
            'channel_code' => $data['channel_code'],
            'login_method' => $data['login_method'],
            'game_password_cipher' => $data['game_password_cipher'],
            'game_token_cipher' => $data['game_token_cipher'],
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

    public function updateValidatedCredential(
        int $userId,
        int $accountId,
        int $loginMethod,
        string $identity,
        string $encryptedCredential,
        array $activeStatuses
    ): ?array {
        foreach ($this->accounts as $index => $account) {
            $currentMethod = (int)($account['login_method'] ?? 1);
            $currentIdentity = $currentMethod === 1
                ? (string)($account['game_username'] ?? '')
                : (string)($account['game_uid'] ?? '');
            if ((int)$account['user_id'] !== $userId
                || (int)$account['id'] !== $accountId
                || $currentMethod !== $loginMethod
                || $currentIdentity !== $identity
                || (int)($account['desired_running'] ?? 0) !== 0
                || in_array((string)($account['status'] ?? ''), $activeStatuses, true)) {
                continue;
            }
            $column = $loginMethod === 1 ? 'game_password_cipher' : 'game_token_cipher';
            $this->accounts[$index][$column] = $encryptedCredential;
            $this->accounts[$index]['sync_status'] = 'local_unsynced';
            return $this->accounts[$index];
        }
        return null;
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
        unset($this->normalLogs[$accountId], $this->events[$accountId]);
        unset($this->taskStates[$accountId]);
    }

    public function appendLogLines(int $accountId, array $lines, int $maxLines): void
    {
        $this->appendNormalLogLines($accountId, 'legacy', $lines, $maxLines);
    }

    public function appendNormalLogLines(int $accountId, string $sessionId, array $lines, int $maxLines): void
    {
        $sessionId = $sessionId !== '' ? $sessionId : 'legacy';
        $this->normalLogs[$accountId][$sessionId] ??= [];
        $lastLine = 0;
        foreach ($this->normalLogs[$accountId][$sessionId] as $row) {
            $lastLine = max($lastLine, (int)$row['line_no']);
        }
        foreach ($lines as $line) {
            $this->normalLogs[$accountId][$sessionId][] = [
                'id' => count($this->normalLogs[$accountId][$sessionId]) + 1,
                'game_account_id' => $accountId,
                'line_no' => ++$lastLine,
                'message' => $line,
                'created_at' => '2026-07-03 00:00:00',
            ];
        }
        if (count($this->normalLogs[$accountId][$sessionId]) > $maxLines) {
            $this->normalLogs[$accountId][$sessionId] = array_slice($this->normalLogs[$accountId][$sessionId], -$maxLines);
        }
        $this->logs[$accountId] = $this->normalLogs[$accountId][$sessionId];
    }

    public function listLogLines(int $accountId, int $afterLine, int $limit): array
    {
        return $this->listNormalLogLines($accountId, 'legacy', $afterLine, $limit);
    }

    public function listNormalLogLines(int $accountId, string $sessionId, int $afterLine, int $limit): array
    {
        $sessionId = $sessionId !== '' ? $sessionId : 'legacy';
        $rows = array_values(array_filter(
            $this->normalLogs[$accountId][$sessionId] ?? [],
            static fn (array $row): bool => (int)$row['line_no'] > $afterLine
        ));
        return array_slice($rows, 0, $limit);
    }

    public function countLogLines(int $accountId): int
    {
        return $this->countNormalLogLines($accountId, 'legacy');
    }

    public function countNormalLogLines(int $accountId, string $sessionId): int
    {
        $sessionId = $sessionId !== '' ? $sessionId : 'legacy';
        return count($this->normalLogs[$accountId][$sessionId] ?? []);
    }

    public function clearLogLines(int $accountId): void
    {
        $this->clearNormalLogLines($accountId, null);
    }

    public function clearNormalLogLines(int $accountId, ?string $sessionId = null): void
    {
        if ($sessionId === null || $sessionId === '') {
            $this->normalLogs[$accountId] = [];
            $this->logs[$accountId] = [];
            return;
        }
        $this->normalLogs[$accountId][$sessionId] = [];
        $this->logs[$accountId] = [];
    }

    public function appendEventLogs(int $accountId, array $events, int $maxEvents): void
    {
        $this->events[$accountId] ??= [];
        $lastEventNo = 0;
        foreach ($this->events[$accountId] as $event) {
            $lastEventNo = max($lastEventNo, (int)$event['event_no']);
        }
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $event['event_no'] = ++$lastEventNo;
            $event['created_at'] = '2026-07-03 00:00:00';
            $this->events[$accountId][] = $event;
        }
        if (count($this->events[$accountId]) > $maxEvents) {
            $this->events[$accountId] = array_slice($this->events[$accountId], -$maxEvents);
        }
    }

    public function listEventLogs(int $accountId, int $afterEventNo, int $limit): array
    {
        $rows = array_values(array_filter(
            $this->events[$accountId] ?? [],
            static fn (array $row): bool => (int)$row['event_no'] > $afterEventNo
        ));
        return array_slice($rows, 0, $limit);
    }

    public function countEventLogs(int $accountId): int
    {
        return count($this->events[$accountId] ?? []);
    }

    public function clearEventLogs(int $accountId): void
    {
        $this->events[$accountId] = [];
    }

    public function taskState(int $accountId): ?array
    {
        return $this->taskStates[$accountId] ?? null;
    }

    public function saveTaskState(int $accountId, string $stateJson, string $stateHash, int $stateBytes, string $savedAt): array
    {
        $now = '2026-07-08 00:00:00';
        $this->taskStates[$accountId] = [
            'id' => $this->taskStates[$accountId]['id'] ?? count($this->taskStates) + 1,
            'game_account_id' => $accountId,
            'state_json' => $stateJson,
            'state_hash' => $stateHash,
            'state_bytes' => $stateBytes,
            'saved_at' => $savedAt,
            'created_at' => $this->taskStates[$accountId]['created_at'] ?? $now,
            'updated_at' => $now,
        ];

        return $this->taskStates[$accountId];
    }

    public function saveTaskStates(array $states): array
    {
        $saved = 0;
        $unchanged = 0;
        $missing = 0;
        $latestByAccount = [];
        foreach ($states as $state) {
            $accountId = (int)($state['game_account_id'] ?? 0);
            if ($accountId > 0) {
                $latestByAccount[$accountId] = $state;
            }
        }

        foreach ($latestByAccount as $accountId => $state) {
            if (!$this->findById($accountId)) {
                $missing++;
                continue;
            }
            if (isset($this->taskStates[$accountId]) && hash_equals((string)$this->taskStates[$accountId]['state_hash'], (string)($state['state_hash'] ?? ''))) {
                $unchanged++;
                continue;
            }
            $this->saveTaskState(
                $accountId,
                (string)($state['state_json'] ?? ''),
                (string)($state['state_hash'] ?? ''),
                (int)($state['state_bytes'] ?? 0),
                (string)($state['saved_at'] ?? '2026-07-08 00:00:00')
            );
            $saved++;
        }

        return [
            'saved' => $saved,
            'unchanged' => $unchanged,
            'missing' => $missing,
        ];
    }

    public function deleteTaskState(int $accountId): void
    {
        unset($this->taskStates[$accountId]);
    }
}
