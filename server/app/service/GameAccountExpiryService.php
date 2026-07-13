<?php

namespace app\service;

use app\repository\GameAccountRepositoryInterface;

class GameAccountExpiryService
{
    private const ACTIVE_STATUSES = [
        GameAccountService::STARTING_STATUS,
        GameAccountService::RUNNING_STATUS,
        GameAccountService::RECONNECTING_STATUS,
    ];

    public function __construct(
        private GameAccountRepositoryInterface $accounts,
        private ThirdPartyScriptRuntimeInterface $runtime,
        private GameLogSinkInterface $logs,
        private mixed $nowProvider = null
    )
    {
        $this->nowProvider ??= static fn (): int => time();
    }

    public function stopExpiredActiveAccounts(int $limit = 100): array
    {
        $result = [
            'checked' => 0,
            'stopping' => 0,
            'stopped' => 0,
            'skipped' => 0,
        ];

        $nowText = $this->dateTime($this->now());
        $accounts = $this->accounts->listExpiredActiveAccounts(self::ACTIVE_STATUSES, $nowText, $limit);
        foreach ($accounts as $account) {
            $result['checked']++;
            $accountId = (int)($account['id'] ?? 0);
            $userId = (int)($account['user_id'] ?? 0);
            if ($accountId <= 0 || $userId <= 0) {
                $result['skipped']++;
                continue;
            }

            $runtime = $this->runtime->stopAccount($accountId, bin2hex(random_bytes(16)));
            $sessionId = (string)($account['log_session_id'] ?? '');
            $status = ($runtime['sent'] ?? false)
                ? GameAccountService::STOPPING_STATUS
                : GameAccountService::STOPPED_STATUS;

            $this->accounts->updateRuntimeState($userId, $accountId, [
                'status' => $status,
                'sync_status' => GameAccountService::LOCAL_UNSYNCED_STATUS,
                'desired_running' => 0,
                'auto_restart_attempts' => 0,
                'auto_restart_next_at' => null,
                'auto_restart_last_error' => 'client.logs.system.quota_expired_stop_sent',
            ]);

            $this->logs->enqueueNormal($accountId, [GameLogMessage::localized('WARN', 'client.logs.system.quota_expired_stop_sent')], $sessionId);
            $result[$status === GameAccountService::STOPPING_STATUS ? 'stopping' : 'stopped']++;
        }

        return $result;
    }

    private function now(): int
    {
        return (int)($this->nowProvider)();
    }

    private function dateTime(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
