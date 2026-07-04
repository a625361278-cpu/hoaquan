<?php

namespace app\service;

use app\exception\ApiException;
use app\repository\GameAccountRepositoryInterface;
use app\support\ApiResponse;
use app\support\I18n;

class GameAccountLogService
{
    public const MAX_LINES_PER_ACCOUNT = 2500;

    public function __construct(
        private GameAccountRepositoryInterface $accounts,
        private string $locale = I18n::DEFAULT_LOCALE
    )
    {
        $this->locale = I18n::normalizeLocale($this->locale);
    }

    public function logsForUser(int $userId, int $accountId, int $lastLine = 0): array
    {
        $account = $this->accounts->findByUserId($userId, $accountId);
        if (!$account) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        return $this->publicLogs($accountId, $lastLine, (string)($account['third_party_account_id'] ?? ''));
    }

    public function appendFromThirdParty(int $accountId, array $lines): array
    {
        $account = $this->accounts->findById($accountId);
        if (!$account) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        $this->accounts->appendLogLines($accountId, $this->normalizeLines($lines), self::MAX_LINES_PER_ACCOUNT);

        return $this->publicLogs($accountId, 0, (string)($account['third_party_account_id'] ?? ''));
    }

    public function clearForUser(int $userId, int $accountId): array
    {
        if (!$this->accounts->findByUserId($userId, $accountId)) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        $this->accounts->clearLogLines($accountId);
        return ApiResponse::success([], I18n::t('api.game.logs_cleared', [], $this->locale));
    }

    private function publicLogs(int $accountId, int $lastLine, string $thirdPartyAccountId): array
    {
        $rows = $this->accounts->listLogLines($accountId, max(0, $lastLine), self::MAX_LINES_PER_ACCOUNT);
        $lastSequence = 0;
        $logs = [];
        foreach ($rows as $row) {
            $lastSequence = max($lastSequence, (int)$row['line_no']);
            $logs[] = (string)$row['message'];
        }

        return ApiResponse::success([
            'count' => $this->accounts->countLogLines($accountId),
            'lastLine' => $lastSequence,
            'logs' => $logs,
            'pod_id' => $thirdPartyAccountId,
            'max_lines' => self::MAX_LINES_PER_ACCOUNT,
            'success' => true,
        ], I18n::t('api.common.ok', [], $this->locale));
    }

    private function normalizeLines(array $lines): array
    {
        $normalized = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $normalized[] = $line;
            }
        }
        return $normalized;
    }
}
