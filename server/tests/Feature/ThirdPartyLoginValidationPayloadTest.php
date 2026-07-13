<?php

namespace tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use plugin\webman\gateway\Events;

class ThirdPartyLoginValidationPayloadTest extends TestCase
{
    #[DataProvider('payloads')]
    public function testLoginValidationResponseSchema(array $payload, bool $expected): void
    {
        $method = new \ReflectionMethod(Events::class, 'validLoginValidationPayload');
        $method->setAccessible(true);
        $this->assertSame($expected, $method->invoke(null, $payload));
    }

    public static function payloads(): array
    {
        $base = [
            'type' => 'login',
            'request_id' => 'request-1',
            'session_id' => 'session-1',
            'msg' => '登录成功',
        ];
        return [
            'success' => [$base + ['code' => 1, 'server_name' => 'VN-202'], true],
            'rejected' => [$base + ['code' => 0], true],
            'string code rejected' => [$base + ['code' => '1', 'server_name' => 'VN-202'], false],
            'missing server on success' => [$base + ['code' => 1], false],
            'blank server on success' => [$base + ['code' => 1, 'server_name' => '  '], false],
            'invalid code' => [$base + ['code' => 2, 'server_name' => 'VN-202'], false],
            'non string msg' => [[
                'type' => 'login',
                'request_id' => 'request-1',
                'session_id' => 'session-1',
                'code' => 0,
                'msg' => 123,
            ], false],
        ];
    }
}
