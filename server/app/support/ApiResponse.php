<?php

namespace app\support;

use support\Response;

class ApiResponse
{
    public static function success(array $data = [], string $message = 'ok'): array
    {
        return [
            'code' => 0,
            'msg' => $message,
            'data' => $data,
        ];
    }

    public static function json(array $payload): Response
    {
        return json($payload);
    }
}
