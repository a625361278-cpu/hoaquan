<?php

namespace tests\Feature;

use app\process\GameAccountLogWebSocket;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Workerman\Protocols\Http\Request;

class GameAccountLogWebSocketTest extends TestCase
{
    public function testHandshakeCallbackAcceptsWorkermanRequest(): void
    {
        $method = new ReflectionMethod(GameAccountLogWebSocket::class, 'onWebSocketConnect');
        $requestType = $method->getParameters()[1]->getType();

        $this->assertNotNull($requestType);
        $this->assertSame(Request::class, $requestType->getName());
    }

    public function testRequestContextReadsAccountAndQueryFromWorkermanRequest(): void
    {
        $request = new Request(
            "GET /ws/game-accounts/15/logs?token=token%2Bvalue&locale=vi HTTP/1.1\r\n"
            . "Host: example.com\r\nConnection: Upgrade\r\n\r\n"
        );

        $this->assertSame([
            'account_id' => 15,
            'token' => 'token+value',
            'locale' => 'vi',
        ], $this->requestContext($request));
    }

    public function testRequestContextRejectsWrongPath(): void
    {
        $request = new Request(
            "GET /ws/game-accounts/15/other?token=test HTTP/1.1\r\n"
            . "Host: example.com\r\nConnection: Upgrade\r\n\r\n"
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('Bad websocket path');

        $this->requestContext($request);
    }

    public function testRequestContextRejectsNonGetRequest(): void
    {
        $request = new Request(
            "POST /ws/game-accounts/15/logs?token=test HTTP/1.1\r\n"
            . "Host: example.com\r\nConnection: Upgrade\r\n\r\n"
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(400);

        $this->requestContext($request);
    }

    private function requestContext(Request $request): array
    {
        $method = new ReflectionMethod(GameAccountLogWebSocket::class, 'requestContext');
        $method->setAccessible(true);

        return $method->invoke(new GameAccountLogWebSocket(), $request);
    }
}
