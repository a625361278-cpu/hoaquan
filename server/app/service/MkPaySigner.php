<?php

namespace app\service;

use JsonException;
use RuntimeException;

final class MkPaySigner
{
    public function requestSignature(string $timestamp, string $body, string $secret): string
    {
        if ($timestamp === '' || $secret === '') {
            throw new RuntimeException('MkPay 签名时间戳或商户密钥为空');
        }
        return hash_hmac('sha256', $timestamp . $body, $secret);
    }

    public function callbackSignature(array $parameters, string $secret): string
    {
        $timestamp = trim((string)($parameters['timestamp'] ?? ''));
        if (!preg_match('/^\d+$/', $timestamp)) {
            throw new RuntimeException('MkPay 回调时间戳无效');
        }
        unset($parameters['sign']);
        try {
            $body = json_encode(
                $this->sortRecursively($parameters),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('MkPay 回调签名 JSON 编码失败', 0, $e);
        }
        return $this->requestSignature($timestamp, $body, $secret);
    }

    public function verifyCallback(array $parameters, string $secret): bool
    {
        $provided = strtolower(trim((string)($parameters['sign'] ?? '')));
        return preg_match('/^[a-f0-9]{64}$/', $provided) === 1
            && hash_equals($this->callbackSignature($parameters, $secret), $provided);
    }

    private function sortRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursively($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }
}
