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
use InvalidArgumentException;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

class GameAccountLogWebSocket
{
    public function onWebSocketConnect(TcpConnection $connection, Request $request): void
    {
        try {
            $context = $this->requestContext($request);
            $locale = I18n::normalizeLocale($context['locale']);
            $userId = $this->authService($locale)->resolveUserId($context['token']);
            $accountId = $context['account_id'];
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

    private function requestContext(Request $request): array
    {
        if ($request->method() !== 'GET'
            || !preg_match('#^/ws/game-accounts/(\d+)/logs$#', $request->path(), $matches)) {
            throw new InvalidArgumentException('Bad websocket path', 400);
        }

        return [
            'account_id' => (int)$matches[1],
            'token' => (string)$request->get('token', ''),
            'locale' => (string)$request->get('locale', I18n::DEFAULT_LOCALE),
        ];
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
