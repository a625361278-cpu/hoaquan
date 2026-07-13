<?php

namespace app\repository;

use support\Db;

class DbGameAccountRepository implements GameAccountRepositoryInterface
{
    private const LOG_SEGMENT_SIZE = 100;

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

    public function listAutoRestartCandidates(array $statuses, string $now, int $limit): array
    {
        if ($statuses === [] || $limit <= 0) {
            return [];
        }

        return Db::table('ga_game_accounts')
            ->where('desired_running', 1)
            ->whereIn('status', $statuses)
            ->where(function ($query) use ($now) {
                $query->whereNull('auto_restart_next_at')
                    ->orWhere('auto_restart_next_at', '<=', $now);
            })
            ->orderBy('auto_restart_next_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(static fn ($row): array => (array)$row)
            ->all();
    }

    public function listDesiredRunningAccounts(array $statuses, int $afterId, int $limit): array
    {
        if ($statuses === [] || $limit <= 0) {
            return [];
        }

        return Db::table('ga_game_accounts')
            ->where('desired_running', 1)
            ->whereIn('status', $statuses)
            ->where('id', '>', max(0, $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(static fn ($row): array => (array)$row)
            ->all();
    }

    public function listExpiredActiveAccounts(array $statuses, string $now, int $limit): array
    {
        if ($statuses === [] || $limit <= 0) {
            return [];
        }

        return Db::table('ga_game_accounts')
            ->whereIn('status', $statuses)
            ->whereNotNull('expire_time')
            ->where('expire_time', '<=', $now)
            ->orderBy('expire_time')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(static fn ($row): array => (array)$row)
            ->all();
    }

    public function createLocalPreviewWithinLimit(int $userId, array $data, int $maxAccounts): ?array
    {
        if ($maxAccounts <= 0) {
            throw new \InvalidArgumentException('Game account limit must be positive');
        }

        return Db::transaction(function () use ($userId, $data, $maxAccounts): ?array {
            $user = Db::table('ga_users')->where('id', $userId)->lockForUpdate()->first();
            if (!$user) {
                throw new \RuntimeException('GameAssist user not found while creating game account');
            }

            $currentCount = Db::table('ga_game_accounts')->where('user_id', $userId)->count();
            if ($currentCount >= $maxAccounts) {
                return null;
            }

            $now = date('Y-m-d H:i:s');
            $id = Db::table('ga_game_accounts')->insertGetId([
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
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->findByUserId($userId, (int)$id) ?? throw new \RuntimeException('Created game account cannot be loaded');
        });
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

    public function updateValidatedCredential(
        int $userId,
        int $accountId,
        int $loginMethod,
        string $identity,
        string $encryptedCredential,
        array $activeStatuses
    ): ?array {
        $identityColumn = $loginMethod === 1 ? 'game_username' : 'game_uid';
        $credentialColumn = $loginMethod === 1 ? 'game_password_cipher' : 'game_token_cipher';
        $query = Db::table('ga_game_accounts')
            ->where('user_id', $userId)
            ->where('id', $accountId)
            ->where('login_method', $loginMethod)
            ->where($identityColumn, $identity)
            ->where('desired_running', 0);
        if ($activeStatuses !== []) {
            $query->whereNotIn('status', $activeStatuses);
        }
        $changed = $query->update([
            $credentialColumn => $encryptedCredential,
            'sync_status' => 'local_unsynced',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($changed !== 1) {
            return null;
        }
        return $this->findByUserId($userId, $accountId)
            ?? throw new \RuntimeException('Validated credential was updated but account cannot be loaded');
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
            'desired_running',
            'auto_restart_attempts',
            'auto_restart_next_at',
            'auto_restart_last_error',
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
        Db::table('ga_game_account_log_segments')->where('game_account_id', $accountId)->delete();
        Db::table('ga_game_account_event_segments')->where('game_account_id', $accountId)->delete();
        Db::table('ga_game_account_log_states')->where('game_account_id', $accountId)->delete();
        Db::table('ga_game_account_task_states')->where('game_account_id', $accountId)->delete();
        Db::table('ga_game_accounts')
            ->where('user_id', $userId)
            ->where('id', $accountId)
            ->delete();
    }

    public function appendLogLines(int $accountId, array $lines, int $maxLines): void
    {
        $this->appendNormalLogLines($accountId, '', $lines, $maxLines);
    }

    public function appendNormalLogLines(int $accountId, string $sessionId, array $lines, int $maxLines): void
    {
        if ($lines === []) {
            return;
        }

        $sessionId = $sessionId !== '' ? $sessionId : 'legacy';
        $state = $this->logState($accountId, 'normal', $sessionId);
        $lastLine = (int)$state['last_sequence'];
        $now = date('Y-m-d H:i:s');

        $records = [];
        foreach ($lines as $line) {
            $records[] = [
                'line_no' => ++$lastLine,
                'message' => $line,
                'created_at' => $now,
            ];
        }

        $lastSegmentNo = $this->appendSegmentedRecords(
            'ga_game_account_log_segments',
            ['game_account_id' => $accountId, 'session_id' => $sessionId],
            'segment_no',
            'start_line_no',
            'end_line_no',
            $records,
            $now,
            $state
        );
        $entryCount = min($maxLines, (int)$state['entry_count'] + count($records));
        $this->trimSegments(
            'ga_game_account_log_segments',
            ['game_account_id' => $accountId, 'session_id' => $sessionId],
            'start_line_no',
            'end_line_no',
            max(0, (int)$state['entry_count'] + count($records) - $maxLines)
        );
        $this->updateLogState($accountId, 'normal', $sessionId, $lastLine, $entryCount, $lastSegmentNo);
    }

    public function listLogLines(int $accountId, int $afterLine, int $limit): array
    {
        return $this->listNormalLogLines($accountId, 'legacy', $afterLine, $limit);
    }

    public function listNormalLogLines(int $accountId, string $sessionId, int $afterLine, int $limit): array
    {
        $sessionId = $sessionId !== '' ? $sessionId : 'legacy';
        $segments = Db::table('ga_game_account_log_segments')
            ->where('game_account_id', $accountId)
            ->where('session_id', $sessionId)
            ->where('end_line_no', '>', $afterLine)
            ->orderBy('start_line_no')
            ->get()
            ->map(static fn ($row): array => (array)$row)
            ->all();

        $rows = [];
        foreach ($segments as $segment) {
            foreach ($this->decodePayload((string)($segment['payload_json'] ?? '[]')) as $record) {
                if ((int)($record['line_no'] ?? 0) <= $afterLine) {
                    continue;
                }
                $rows[] = [
                    'line_no' => (int)$record['line_no'],
                    'message' => (string)($record['message'] ?? ''),
                    'created_at' => (string)($record['created_at'] ?? ''),
                ];
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    public function countLogLines(int $accountId): int
    {
        return $this->countNormalLogLines($accountId, 'legacy');
    }

    public function countNormalLogLines(int $accountId, string $sessionId): int
    {
        $sessionId = $sessionId !== '' ? $sessionId : 'legacy';
        return (int)$this->logState($accountId, 'normal', $sessionId)['entry_count'];
    }

    public function clearLogLines(int $accountId): void
    {
        $this->clearNormalLogLines($accountId, null);
    }

    public function clearNormalLogLines(int $accountId, ?string $sessionId = null): void
    {
        $query = Db::table('ga_game_account_log_segments')->where('game_account_id', $accountId);
        if ($sessionId !== null && $sessionId !== '') {
            $query->where('session_id', $sessionId);
        }
        $query->delete();
        $stateQuery = Db::table('ga_game_account_log_states')
            ->where('game_account_id', $accountId)
            ->where('log_type', 'normal');
        if ($sessionId !== null && $sessionId !== '') {
            $stateQuery->where('session_id', $sessionId);
        }
        $stateQuery->delete();
    }

    public function appendEventLogs(int $accountId, array $events, int $maxEvents): void
    {
        if ($events === []) {
            return;
        }

        $state = $this->logState($accountId, 'event', '');
        $lastEventNo = (int)$state['last_sequence'];
        $now = date('Y-m-d H:i:s');
        $records = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $records[] = [
                'event_no' => ++$lastEventNo,
                'event' => $event,
                'created_at' => $now,
            ];
        }
        if ($records === []) {
            return;
        }

        $lastSegmentNo = $this->appendSegmentedRecords(
            'ga_game_account_event_segments',
            ['game_account_id' => $accountId],
            'segment_no',
            'start_event_no',
            'end_event_no',
            $records,
            $now,
            $state
        );
        $entryCount = min($maxEvents, (int)$state['entry_count'] + count($records));
        $this->trimSegments(
            'ga_game_account_event_segments',
            ['game_account_id' => $accountId],
            'start_event_no',
            'end_event_no',
            max(0, (int)$state['entry_count'] + count($records) - $maxEvents)
        );
        $this->updateLogState($accountId, 'event', '', $lastEventNo, $entryCount, $lastSegmentNo);
    }

    public function listEventLogs(int $accountId, int $afterEventNo, int $limit): array
    {
        $segments = Db::table('ga_game_account_event_segments')
            ->where('game_account_id', $accountId)
            ->where('end_event_no', '>', $afterEventNo)
            ->orderBy('start_event_no')
            ->get()
            ->map(static fn ($row): array => (array)$row)
            ->all();

        $events = [];
        foreach ($segments as $segment) {
            foreach ($this->decodePayload((string)($segment['payload_json'] ?? '[]')) as $record) {
                if ((int)($record['event_no'] ?? 0) <= $afterEventNo) {
                    continue;
                }
                $event = is_array($record['event'] ?? null) ? $record['event'] : [];
                $event['event_no'] = (int)$record['event_no'];
                $event['created_at'] = (string)($record['created_at'] ?? '');
                $events[] = $event;
                if (count($events) >= $limit) {
                    return $events;
                }
            }
        }
        return $events;
    }

    public function countEventLogs(int $accountId): int
    {
        return (int)$this->logState($accountId, 'event', '')['entry_count'];
    }

    public function clearEventLogs(int $accountId): void
    {
        Db::table('ga_game_account_event_segments')->where('game_account_id', $accountId)->delete();
        Db::table('ga_game_account_log_states')
            ->where('game_account_id', $accountId)
            ->where('log_type', 'event')
            ->delete();
    }

    public function taskState(int $accountId): ?array
    {
        $row = Db::table('ga_game_account_task_states')
            ->where('game_account_id', $accountId)
            ->first();

        return $row ? (array)$row : null;
    }

    public function saveTaskState(int $accountId, string $stateJson, string $stateHash, int $stateBytes, string $savedAt): array
    {
        $now = date('Y-m-d H:i:s');
        $exists = Db::table('ga_game_account_task_states')
            ->where('game_account_id', $accountId)
            ->exists();

        $data = [
            'state_json' => $stateJson,
            'state_hash' => $stateHash,
            'state_bytes' => $stateBytes,
            'saved_at' => $savedAt,
            'updated_at' => $now,
        ];

        if ($exists) {
            Db::table('ga_game_account_task_states')
                ->where('game_account_id', $accountId)
                ->update($data);
        } else {
            Db::table('ga_game_account_task_states')->insert($data + [
                'game_account_id' => $accountId,
                'created_at' => $now,
            ]);
        }

        return $this->taskState($accountId) ?? [];
    }

    public function saveTaskStates(array $states): array
    {
        if ($states === []) {
            return ['saved' => 0, 'unchanged' => 0, 'missing' => 0];
        }

        $latestByAccount = [];
        foreach ($states as $state) {
            $accountId = (int)($state['game_account_id'] ?? 0);
            if ($accountId <= 0) {
                continue;
            }
            $latestByAccount[$accountId] = [
                'game_account_id' => $accountId,
                'state_json' => (string)($state['state_json'] ?? ''),
                'state_hash' => (string)($state['state_hash'] ?? ''),
                'state_bytes' => (int)($state['state_bytes'] ?? 0),
                'saved_at' => (string)($state['saved_at'] ?? date('Y-m-d H:i:s')),
            ];
        }

        if ($latestByAccount === []) {
            return ['saved' => 0, 'unchanged' => 0, 'missing' => 0];
        }

        $accountIds = array_keys($latestByAccount);
        $existingAccountIds = Db::table('ga_game_accounts')
            ->whereIn('id', $accountIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int)$id)
            ->all();
        $existingAccountMap = array_fill_keys($existingAccountIds, true);

        $existingHashes = Db::table('ga_game_account_task_states')
            ->whereIn('game_account_id', $existingAccountIds)
            ->pluck('state_hash', 'game_account_id')
            ->map(static fn ($hash): string => (string)$hash)
            ->all();

        $now = date('Y-m-d H:i:s');
        $rows = [];
        $unchanged = 0;
        $missing = 0;
        foreach ($latestByAccount as $accountId => $state) {
            if (!isset($existingAccountMap[$accountId])) {
                $missing++;
                continue;
            }
            if (isset($existingHashes[$accountId]) && hash_equals((string)$existingHashes[$accountId], $state['state_hash'])) {
                $unchanged++;
                continue;
            }
            $rows[] = [
                'game_account_id' => $accountId,
                'state_json' => $state['state_json'],
                'state_hash' => $state['state_hash'],
                'state_bytes' => $state['state_bytes'],
                'saved_at' => $state['saved_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            Db::table('ga_game_account_task_states')->upsert(
                $rows,
                ['game_account_id'],
                ['state_json', 'state_hash', 'state_bytes', 'saved_at', 'updated_at']
            );
        }

        return [
            'saved' => count($rows),
            'unchanged' => $unchanged,
            'missing' => $missing,
        ];
    }

    public function deleteTaskState(int $accountId): void
    {
        Db::table('ga_game_account_task_states')
            ->where('game_account_id', $accountId)
            ->delete();
    }

    private function appendSegmentedRecords(
        string $table,
        array $where,
        string $segmentNoColumn,
        string $startColumn,
        string $endColumn,
        array $records,
        string $now,
        array $state
    ): int
    {
        $sequenceKey = str_contains($endColumn, 'event') ? 'event_no' : 'line_no';
        $segmentNo = (int)($state['last_segment_no'] ?? 0);
        $lastSegment = null;
        if ($segmentNo > 0) {
            $lastSegment = $this->segmentByNo($table, $where, $segmentNoColumn, $segmentNo);
        }
        if ($lastSegment && (int)$lastSegment['entry_count'] < self::LOG_SEGMENT_SIZE) {
            $payload = $this->decodePayload((string)$lastSegment['payload_json']);
            $space = self::LOG_SEGMENT_SIZE - count($payload);
            $append = array_splice($records, 0, $space);
            if ($append !== []) {
                $payload = array_merge($payload, $append);
                Db::table($table)->where('id', (int)$lastSegment['id'])->update([
                    'entry_count' => count($payload),
                    $endColumn => (int)end($payload)[$sequenceKey],
                    'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'last_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $segmentNo = (int)($lastSegment[$segmentNoColumn] ?? $segmentNo);
        foreach (array_chunk($records, self::LOG_SEGMENT_SIZE) as $chunk) {
            $first = reset($chunk);
            $last = end($chunk);
            Db::table($table)->insert($where + [
                $segmentNoColumn => ++$segmentNo,
                $startColumn => (int)$first[$sequenceKey],
                $endColumn => (int)$last[$sequenceKey],
                'entry_count' => count($chunk),
                'payload_json' => json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'first_at' => $now,
                'last_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return $segmentNo;
    }

    private function trimSegments(string $table, array $where, string $startColumn, string $endColumn, int $overflow): void
    {
        $sequenceKey = str_contains($endColumn, 'event') ? 'event_no' : 'line_no';
        if ($overflow > 0) {
            while ($overflow > 0) {
                $segment = Db::table($table)->where($where)->orderBy($startColumn)->first();
                if (!$segment) {
                    return;
                }
                $segment = (array)$segment;
                $entryCount = (int)$segment['entry_count'];
                if ($entryCount <= $overflow) {
                    Db::table($table)->where('id', (int)$segment['id'])->delete();
                    $overflow -= $entryCount;
                    continue;
                }

                $payload = array_slice($this->decodePayload((string)$segment['payload_json']), $overflow);
                $first = reset($payload);
                $last = end($payload);
                Db::table($table)->where('id', (int)$segment['id'])->update([
                    'entry_count' => count($payload),
                    $startColumn => (int)$first[$sequenceKey],
                    $endColumn => (int)$last[$sequenceKey],
                    'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                return;
            }
        }
    }

    private function segmentByNo(string $table, array $where, string $segmentNoColumn, int $segmentNo): ?array
    {
        $row = Db::table($table)->where($where)->where($segmentNoColumn, $segmentNo)->first();
        return $row ? (array)$row : null;
    }

    private function logState(int $accountId, string $logType, string $sessionId): array
    {
        $sessionId = $logType === 'normal' ? ($sessionId !== '' ? $sessionId : 'legacy') : '';
        $row = Db::table('ga_game_account_log_states')
            ->where('game_account_id', $accountId)
            ->where('log_type', $logType)
            ->where('session_id', $sessionId)
            ->first();
        if ($row) {
            return (array)$row;
        }
        return $this->initializeLogState($accountId, $logType, $sessionId);
    }

    private function initializeLogState(int $accountId, string $logType, string $sessionId): array
    {
        $now = date('Y-m-d H:i:s');
        if ($logType === 'event') {
            $baseQuery = Db::table('ga_game_account_event_segments')
                ->where('game_account_id', $accountId);
            $lastSequence = (int)(clone $baseQuery)->max('end_event_no');
            $entryCount = (int)(clone $baseQuery)->sum('entry_count');
            $lastSegmentNo = (int)(clone $baseQuery)->max('segment_no');
        } else {
            $baseQuery = Db::table('ga_game_account_log_segments')
                ->where('game_account_id', $accountId)
                ->where('session_id', $sessionId);
            $lastSequence = (int)(clone $baseQuery)->max('end_line_no');
            $entryCount = (int)(clone $baseQuery)->sum('entry_count');
            $lastSegmentNo = (int)(clone $baseQuery)->max('segment_no');
        }

        $state = [
            'game_account_id' => $accountId,
            'log_type' => $logType,
            'session_id' => $sessionId,
            'last_sequence' => $lastSequence,
            'entry_count' => $entryCount,
            'last_segment_no' => $lastSegmentNo,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        Db::table('ga_game_account_log_states')->insert($state);
        return $state;
    }

    private function updateLogState(
        int $accountId,
        string $logType,
        string $sessionId,
        int $lastSequence,
        int $entryCount,
        int $lastSegmentNo
    ): void
    {
        $sessionId = $logType === 'normal' ? ($sessionId !== '' ? $sessionId : 'legacy') : '';
        Db::table('ga_game_account_log_states')
            ->where('game_account_id', $accountId)
            ->where('log_type', $logType)
            ->where('session_id', $sessionId)
            ->update([
                'last_sequence' => $lastSequence,
                'entry_count' => $entryCount,
                'last_segment_no' => $lastSegmentNo,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private function decodePayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }
}
