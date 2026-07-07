<?php

namespace app\service;

use app\exception\ApiException;
use app\repository\GameAccountRepositoryInterface;
use Throwable;

class GameAccountAutoRestartService
{
    public const MAX_ATTEMPTS = 10;
    private const DEFAULT_LIMIT = 100;

    public function __construct(
        private GameAccountRepositoryInterface $accounts,
        private ThirdPartyScriptRuntimeInterface $runtime,
        private ThirdPartyScriptConnectionStoreInterface $connections,
        private GameLogSinkInterface $logs,
        private string $credentialKey,
        private mixed $nowProvider = null
    )
    {
        $this->nowProvider ??= static fn (): int => time();
    }

    public function scheduleReconnect(int $accountId, string $reason, string $sessionId = ''): void
    {
        $account = $this->accounts->findById($accountId);
        if (!$account || (int)($account['desired_running'] ?? 0) !== 1) {
            return;
        }

        $status = (string)($account['status'] ?? '');
        if (in_array($status, [GameAccountService::STOPPING_STATUS, GameAccountService::STOPPED_STATUS, GameAccountService::LOCAL_PREVIEW_STATUS], true)) {
            return;
        }

        $sessionId = $sessionId !== '' ? $sessionId : (string)($account['log_session_id'] ?? '');
        if ($sessionId === '') {
            $sessionId = bin2hex(random_bytes(12));
        }

        $this->accounts->updateRuntimeState((int)$account['user_id'], $accountId, [
            'status' => GameAccountService::RECONNECTING_STATUS,
            'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
            'log_session_id' => $sessionId,
            'auto_restart_next_at' => $this->dateTime($this->now()),
            'auto_restart_last_error' => $reason,
        ]);

        $message = '[WARN] ' . $reason . '，等待自动重连';
        $this->logs->enqueueNormal($accountId, [$message], $sessionId);
        $this->logs->enqueueEvents($accountId, [[
            'category' => 'system',
            'level' => 'warning',
            'title' => '连接断开',
            'message' => $reason . '，等待自动重连',
            'time' => $this->dateTime($this->now()),
        ]]);
    }

    public function runDue(int $limit = self::DEFAULT_LIMIT): array
    {
        $result = [
            'checked' => 0,
            'started' => 0,
            'waiting' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $accounts = $this->accounts->listAutoRestartCandidates(
            [GameAccountService::RECONNECTING_STATUS],
            $this->dateTime($this->now()),
            $limit
        );

        foreach ($accounts as $account) {
            $result['checked']++;
            $accountId = (int)($account['id'] ?? 0);
            if ($accountId <= 0 || (int)($account['desired_running'] ?? 0) !== 1) {
                $result['skipped']++;
                continue;
            }

            if ($this->connections->connectionByAccount($accountId)) {
                $result['skipped']++;
                continue;
            }

            $outcome = $this->attemptReconnect($account);
            $result[$outcome]++;
        }

        return $result;
    }

    public function reconcileMissingBindings(int $afterId = 0, int $limit = self::DEFAULT_LIMIT): array
    {
        $rows = $this->accounts->listDesiredRunningAccounts(
            [GameAccountService::STARTING_STATUS, GameAccountService::RUNNING_STATUS, GameAccountService::RECONNECTING_STATUS],
            $afterId,
            $limit
        );
        $result = [
            'checked' => count($rows),
            'scheduled' => 0,
            'next_cursor' => $afterId,
            'wrapped' => false,
        ];

        if ($rows === []) {
            $result['next_cursor'] = 0;
            $result['wrapped'] = true;
            return $result;
        }

        foreach ($rows as $account) {
            $accountId = (int)($account['id'] ?? 0);
            $result['next_cursor'] = max($result['next_cursor'], $accountId);
            if ($accountId <= 0 || $this->connections->connectionByAccount($accountId)) {
                continue;
            }

            $this->scheduleReconnect($accountId, '运行连接丢失', (string)($account['log_session_id'] ?? ''));
            $result['scheduled']++;
        }

        return $result;
    }

    private function attemptReconnect(array $account): string
    {
        $accountId = (int)($account['id'] ?? 0);
        $userId = (int)($account['user_id'] ?? 0);
        $sessionId = (string)($account['log_session_id'] ?? '');
        if ($sessionId === '') {
            $sessionId = bin2hex(random_bytes(12));
        }
        $requestId = bin2hex(random_bytes(16));

        try {
            $reservation = $this->runtime->reserveAccount($accountId, $requestId, $sessionId);
        } catch (ApiException) {
            $this->accounts->updateRuntimeState($userId, $accountId, [
                'status' => GameAccountService::RECONNECTING_STATUS,
                'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
                'log_session_id' => $sessionId,
                'auto_restart_next_at' => $this->dateTime($this->now() + 2),
            ]);
            return 'waiting';
        } catch (Throwable $e) {
            $this->markAttemptFailure($account, $sessionId, $e->getMessage());
            return 'failed';
        }

        try {
            $this->accounts->updateRuntimeState($userId, $accountId, [
                'status' => GameAccountService::STARTING_STATUS,
                'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
                'log_session_id' => $sessionId,
            ]);
            $gamePassword = (new CredentialCipher($this->credentialKey))->decrypt((string)($account['game_password_cipher'] ?? ''));
            $this->runtime->sendStartCommand(
                $reservation,
                $account,
                $gamePassword,
                $this->decodeConfig((string)($account['config_json'] ?? '{}'))
            );
        } catch (Throwable $e) {
            $this->runtime->releaseReservation($reservation);
            $this->markAttemptFailure($account, $sessionId, $e->getMessage());
            return 'failed';
        }

        $this->accounts->updateRuntimeState($userId, $accountId, [
            'status' => GameAccountService::STARTING_STATUS,
            'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
            'log_session_id' => $sessionId,
            'auto_restart_attempts' => 0,
            'auto_restart_next_at' => null,
            'auto_restart_last_error' => '',
        ]);
        $this->logs->enqueueNormal($accountId, ['[INFO] 已重新下发启动指令，等待服务器确认'], $sessionId);
        return 'started';
    }

    private function markAttemptFailure(array $account, string $sessionId, string $error): void
    {
        $accountId = (int)$account['id'];
        $nextAttempt = (int)($account['auto_restart_attempts'] ?? 0) + 1;

        if ($nextAttempt >= self::MAX_ATTEMPTS) {
            $this->accounts->updateRuntimeState((int)$account['user_id'], $accountId, [
                'status' => GameAccountService::ERROR_STATUS,
                'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
                'desired_running' => 0,
                'auto_restart_attempts' => $nextAttempt,
                'auto_restart_next_at' => null,
                'auto_restart_last_error' => $error,
            ]);
            $this->logs->enqueueNormal($accountId, ['[ERROR] 自动重连失败次数过多，已停止重试：' . $error], $sessionId);
            $this->logs->enqueueEvents($accountId, [[
                'category' => 'system',
                'level' => 'error',
                'title' => '自动重连失败',
                'message' => '自动重连失败次数过多，已停止重试：' . $error,
                'time' => $this->dateTime($this->now()),
            ]]);
            return;
        }

        $this->accounts->updateRuntimeState((int)$account['user_id'], $accountId, [
            'status' => GameAccountService::RECONNECTING_STATUS,
            'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
            'auto_restart_attempts' => $nextAttempt,
            'auto_restart_next_at' => $this->dateTime($this->now() + $this->backoffSeconds($nextAttempt)),
            'auto_restart_last_error' => $error,
        ]);
        $this->logs->enqueueNormal($accountId, ['[WARN] 自动重连失败，将稍后重试：' . $error], $sessionId);
    }

    private function backoffSeconds(int $attempt): int
    {
        return match (true) {
            $attempt <= 1 => 10,
            $attempt === 2 => 30,
            $attempt === 3 => 60,
            default => 300,
        };
    }

    private function now(): int
    {
        return (int)($this->nowProvider)();
    }

    private function dateTime(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function decodeConfig(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $config = json_decode($json, true);
        return is_array($config) ? $config : [];
    }
}
