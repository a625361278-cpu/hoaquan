<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;
use plugin\webman\gateway\Events;
use ReflectionMethod;

class ThirdPartyScriptHandshakeTest extends TestCase
{
    public function testGatewayWorkerHandshakeArrayQueryIsParsed(): void
    {
        $query = $this->parseQuery([
            'get' => [
                'token' => 'script-token',
                'locale' => 'vi',
                'version' => '1.2.3',
            ],
            'server' => [
                'QUERY_STRING' => 'token=wrong',
                'REMOTE_ADDR' => '127.0.0.1',
            ],
        ]);

        $this->assertSame('script-token', $query['token']);
        $this->assertSame('vi', $query['locale']);
        $this->assertSame('1.2.3', $query['version']);
    }

    public function testGatewayWorkerHandshakeArrayFallsBackToQueryString(): void
    {
        $query = $this->parseQuery([
            'get' => [],
            'server' => [
                'QUERY_STRING' => 'token=script-token&locale=zh_CN',
            ],
        ]);

        $this->assertSame('script-token', $query['token']);
        $this->assertSame('zh_CN', $query['locale']);
    }

    public function testRawHttpHandshakeStringStillParsesQuery(): void
    {
        $query = $this->parseQuery("GET /ws/third-party/script?token=script-token&version=1 HTTP/1.1\r\nHost: example.com\r\n\r\n");

        $this->assertSame('script-token', $query['token']);
        $this->assertSame('1', $query['version']);
    }

    private function parseQuery(mixed $data): array
    {
        $method = new ReflectionMethod(Events::class, 'queryFromHandshake');
        $method->setAccessible(true);

        return $method->invoke(null, $data);
    }
}
