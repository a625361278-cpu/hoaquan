<?php

namespace support\exception;

use app\exception\ApiException;
use Throwable;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;

class Handler extends ExceptionHandler
{
    public $dontReport = [
        ApiException::class,
    ];

    public function render(Request $request, Throwable $exception): Response
    {
        if ($exception instanceof ApiException) {
            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'code' => $exception->getApiCode(),
                    'msg' => $exception->getMessage(),
                    'data' => null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return parent::render($request, $exception);
    }
}
