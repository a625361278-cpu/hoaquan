<?php

namespace tests\Feature;

use app\middleware\CorsMiddleware;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CorsMiddlewareTest extends TestCase
{
    public function testCorsResponseAllowsLocaleHeader(): void
    {
        $method = new ReflectionMethod(CorsMiddleware::class, 'withCors');
        $method->setAccessible(true);

        $response = $method->invoke(new CorsMiddleware(), response('', 204));

        $this->assertStringContainsString('X-Locale', $response->getHeader('Access-Control-Allow-Headers'));
    }
}
