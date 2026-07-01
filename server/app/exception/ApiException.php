<?php

namespace app\exception;

use RuntimeException;
use Webman\Http\Request;
use Webman\Http\Response;

class ApiException extends RuntimeException
{
    public function __construct(string $message, private int $apiCode = 400)
    {
        parent::__construct($message, $apiCode);
    }

    public function getApiCode(): int
    {
        return $this->apiCode;
    }

    public function render(Request $request): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'code' => $this->apiCode,
                'msg' => $this->getMessage(),
                'data' => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
