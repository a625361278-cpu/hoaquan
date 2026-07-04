<?php

namespace app\process;

use app\repository\DbGameAccountRepository;
use app\service\AuthService;
use app\service\GameAccountLogService;
use app\service\RedisEmailCodeStore;
use app\service\RedisTokenStore;
use app\service\SmtpMailer;
use app\service\SystemSettingService;
use app\repository\DbUserRepository;
use app\support\I18n;
use Throwable;
use Workerman\Connection\TcpConnection;

class GameAccountLogWebSocket
{
    public function onWebSocketConnect(TcpConnection $connection, string $buffer): void
    {
        try {
            $requestLine = strtok($buffer, "\r\n") ?: '';
            if (!preg_match('#GET\s+/ws/game-accounts/(\d+)/logs\?([^ ]*)\s+HTTP#', $requestLine, $matches)) {
                $connection->close(json_encode(['code' => 400, 'msg' => 'Bad websocket path'], JSON_UNESCAPED_UNICODE));
                return;
            }

            parse_str($matches[2], $query);
            $locale = I18n::normalizeLocale((string)($query['locale'] ?? I18n::DEFAULT_LOCALE));
            $userId = $this->authService($locale)->resolveUserId((string)($query['token'] ?? ''));
            $accountId = (int)$matches[1];
            $payload = (new GameAccountLogService(new DbGameAccountRepository(), $locale))
                ->logsForUser($userId, $accountId, 0);

            $connection->send(json_encode($payload['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            $connection->close(json_encode([
                'code' => $e->getCode() ?: 500,
                'msg' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    public function onMessage(TcpConnection $connection, string $data): void
    {
        $connection->send(json_encode(['type' => 'pong'], JSON_UNESCAPED_UNICODE));
    }

    private function authService(string $locale): AuthService
    {
        $settings = new SystemSettingService();
        return new AuthService(
            new DbUserRepository(),
            new RedisTokenStore(),
            new RedisEmailCodeStore($locale),
            new SmtpMailer($settings, $locale),
            $locale
        );
    }
}
