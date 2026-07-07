<?php

namespace app\service;

use app\exception\ApiException;
use app\repository\GameAccountRepositoryInterface;
use app\support\ApiResponse;
use app\support\I18n;

class GameAccountLogService
{
    public const MAX_LINES_PER_ACCOUNT = 2500;
    public const MAX_EVENTS_PER_ACCOUNT = 2500;

    public function __construct(
        private GameAccountRepositoryInterface $accounts,
        private string $locale = I18n::DEFAULT_LOCALE,
        private ?GameLogNormalizer $normalizer = null
    )
    {
        $this->locale = I18n::normalizeLocale($this->locale);
        $this->normalizer ??= new GameLogNormalizer();
    }

    public function logsForUser(int $userId, int $accountId, int $lastLine = 0, int $lastEvent = 0): array
    {
        $account = $this->accounts->findByUserId($userId, $accountId);
        if (!$account) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        return $this->publicLogs($account, $lastLine, $lastEvent);
    }

    public function appendFromThirdParty(int $accountId, array $lines): array
    {
        $account = $this->accounts->findById($accountId);
        if (!$account) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        $normalized = $this->normalizer->normalizeLines($lines);
        $sessionId = (string)($account['log_session_id'] ?? '');
        if ($sessionId === '') {
            throw new ApiException(I18n::t('api.game.log_session_missing', [], $this->locale), 409);
        }

        $this->accounts->appendNormalLogLines($accountId, $sessionId, $normalized, self::MAX_LINES_PER_ACCOUNT);
        $events = $this->normalizer->eventsFromLines($normalized);
        if ($events !== []) {
            $this->accounts->appendEventLogs($accountId, $events, self::MAX_EVENTS_PER_ACCOUNT);
        }

        return $this->publicLogs($account, 0, 0);
    }

    public function appendEventsFromThirdParty(int $accountId, array $events): array
    {
        $account = $this->accounts->findById($accountId);
        if (!$account) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        $normalized = $this->normalizer->normalizeEvents($events);
        if ($normalized !== []) {
            $this->accounts->appendEventLogs($accountId, $normalized, self::MAX_EVENTS_PER_ACCOUNT);
        }
        return $this->publicLogs($account, 0, 0);
    }

    public function clearForUser(int $userId, int $accountId, string $type = 'normal'): array
    {
        if (!$this->accounts->findByUserId($userId, $accountId)) {
            throw new ApiException(I18n::t('api.game.account_not_found', [], $this->locale), 404);
        }

        if ($type === 'event') {
            $this->accounts->clearEventLogs($accountId);
        } elseif ($type === 'all') {
            $this->accounts->clearNormalLogLines($accountId, null);
            $this->accounts->clearEventLogs($accountId);
        } else {
            $this->accounts->clearNormalLogLines($accountId, null);
        }

        return ApiResponse::success([], I18n::t('api.game.logs_cleared', [], $this->locale));
    }

    private function publicLogs(array $account, int $lastLine, int $lastEvent): array
    {
        $accountId = (int)$account['id'];
        $sessionId = (string)($account['log_session_id'] ?? '');
        $rows = $sessionId === ''
            ? []
            : $this->accounts->listNormalLogLines($accountId, $sessionId, max(0, $lastLine), self::MAX_LINES_PER_ACCOUNT);
        $lastSequence = 0;
        $logs = [];
        foreach ($rows as $row) {
            $lastSequence = max($lastSequence, (int)$row['line_no']);
            $logs[] = (string)$row['message'];
        }

        $events = $this->accounts->listEventLogs($accountId, max(0, $lastEvent), self::MAX_EVENTS_PER_ACCOUNT);
        $lastEventNo = 0;
        $categories = [];
        foreach ($events as $event) {
            $lastEventNo = max($lastEventNo, (int)($event['event_no'] ?? 0));
            $module = trim((string)($event['module'] ?? ''));
            if ($module === '') {
                $module = I18n::t('client.logs.all', [], $this->locale);
            }
            $categories[$module] = ($categories[$module] ?? 0) + 1;
        }
        arsort($categories);

        return ApiResponse::success([
            'count' => $sessionId === '' ? 0 : $this->accounts->countNormalLogLines($accountId, $sessionId),
            'lastLine' => $lastSequence,
            'logs' => $logs,
            'events' => $events,
            'event_count' => $this->accounts->countEventLogs($accountId),
            'lastEvent' => $lastEventNo,
            'categories' => $categories,
            'pod_id' => (string)($account['third_party_account_id'] ?? ''),
            'log_session_id' => $sessionId,
            'max_lines' => self::MAX_LINES_PER_ACCOUNT,
            'max_events' => self::MAX_EVENTS_PER_ACCOUNT,
            'success' => true,
        ], I18n::t('api.common.ok', [], $this->locale));
    }

}
