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

    public function testInvalidMessageDiagnosticsDescribeShapeWithoutLoggingRawPayload(): void
    {
        $message = "\xEF\xBB\xBF" . '{"type":"heartbeat"';
        json_decode($message);
        $errorCode = json_last_error();
        $errorMessage = json_last_error_msg();

        $method = new ReflectionMethod(Events::class, 'invalidMessageDiagnostics');
        $method->setAccessible(true);
        $diagnostics = $method->invoke(null, $message, $errorCode, $errorMessage);

        $this->assertSame(strlen($message), $diagnostics['invalid_message_bytes']);
        $this->assertSame(hash('sha256', $message), $diagnostics['invalid_message_sha256']);
        $this->assertSame($errorCode, $diagnostics['invalid_message_json_error_code']);
        $this->assertFalse($diagnostics['invalid_message_starts_object']);
        $this->assertFalse($diagnostics['invalid_message_ends_object']);
        $this->assertStringStartsWith('efbbbf', $diagnostics['invalid_message_prefix_hex']);
        $this->assertArrayNotHasKey('message', $diagnostics);
        $this->assertArrayNotHasKey('payload', $diagnostics);
    }

    public function testInvalidMessageQuarantinePreservesOriginalBytes(): void
    {
        $message = "\xEF\xBB\xBF" . '{"type":"heartbeat"';
        $method = new ReflectionMethod(Events::class, 'quarantineInvalidMessage');
        $method->setAccessible(true);

        $path = $method->invoke(null, 'client:test/1', $message);
        try {
            $this->assertNotSame('', $path);
            $this->assertFileExists($path);
            $this->assertSame($message, file_get_contents($path));
            $this->assertStringEndsWith('.bin', $path);
            $this->assertStringNotContainsString('heartbeat', basename($path));
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    private function parseQuery(mixed $data): array
    {
        $method = new ReflectionMethod(Events::class, 'queryFromHandshake');
        $method->setAccessible(true);

        return $method->invoke(null, $data);
    }
}
