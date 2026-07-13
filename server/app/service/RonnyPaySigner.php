<?php

namespace app\service;

use RuntimeException;

final class RonnyPaySigner
{
    public function signRequest(array $parameters, string $privateKeyPath): string
    {
        $privateKey = openssl_pkey_get_private($this->readKey($privateKeyPath));
        if ($privateKey === false) {
            throw new RuntimeException('RonnyPay RSA 私钥格式无效');
        }
        $signature = '';
        if (!openssl_sign($this->requestSigningText($parameters), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('RonnyPay RSA-SHA256 签名失败');
        }
        return rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    public function verifyRequestSignature(array $parameters, string $signature, string $publicKey): bool
    {
        $encoded = strtr($signature, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            return false;
        }
        $key = openssl_pkey_get_public($publicKey);
        return $key !== false && openssl_verify($this->requestSigningText($parameters), $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public function requestSigningText(array $parameters): string
    {
        $pairs = [];
        foreach ($this->canonicalParameters($parameters) as $key => $value) {
            $pairs[] = $key . '=' . $value . '&';
        }
        return implode('', $pairs);
    }

    public function callbackSigningText(array $parameters, string $secret): string
    {
        $pairs = [];
        foreach ($this->canonicalParameters($parameters) as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        return implode('&', $pairs) . '&key=' . $secret;
    }

    public function callbackSignature(array $parameters, string $secret): string
    {
        return md5($this->callbackSigningText($parameters, $secret));
    }

    public function verifyCallback(array $parameters, string $secret): bool
    {
        $provided = strtolower(trim((string)($parameters['sign'] ?? '')));
        return $provided !== '' && hash_equals($this->callbackSignature($parameters, $secret), $provided);
    }

    public function canonicalParameters(array $parameters): array
    {
        unset($parameters['sign']);
        $filtered = [];
        foreach ($parameters as $key => $value) {
            if ($value === null || $value === '' || is_array($value) || is_object($value)) {
                continue;
            }
            $filtered[(string)$key] = (string)$value;
        }
        ksort($filtered, SORT_STRING);
        return $filtered;
    }

    private function readKey(string $path): string
    {
        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new RuntimeException('RonnyPay RSA 私钥文件无法读取');
        }
        return $content;
    }
}
