<?php

namespace app\service;

use RuntimeException;

final class RonnyPayConfig
{
    public function __construct(
        private ?array $values = null
    ) {
        $this->values ??= [
            'enabled' => app_env('RONNYPAY_ORDER_ENABLED', '0'),
            'merchant_id' => app_env('RONNYPAY_MERCHANT_ID', ''),
            'private_key_path' => app_env('RONNYPAY_PRIVATE_KEY_PATH', ''),
            'callback_secret' => app_env('RONNYPAY_CALLBACK_SECRET', ''),
            'notify_url' => app_env('RONNYPAY_NOTIFY_URL', ''),
            'wallet_type' => app_env('RONNYPAY_WALLET_TYPE', ''),
            'bank_code' => app_env('RONNYPAY_BANK_CODE', ''),
            'base_url' => app_env('RONNYPAY_BASE_URL', 'https://ronnypay.com'),
        ];
    }

    public function orderEnabled(): bool
    {
        return in_array(strtolower(trim((string)$this->values['enabled'])), ['1', 'true', 'yes', 'on'], true);
    }

    public function assertCanCreateOrder(): void
    {
        $this->assertApiConfigured();
        $this->assertCallbackConfigured();
        $this->requireValue('notify_url', 'RONNYPAY_NOTIFY_URL');
        $this->requireValue('wallet_type', 'RONNYPAY_WALLET_TYPE');
        $this->requireValue('bank_code', 'RONNYPAY_BANK_CODE');
        if (!$this->isHttpsUrl($this->notifyUrl())) {
            throw new RuntimeException('RONNYPAY_NOTIFY_URL 必须是有效的 HTTPS 地址');
        }
    }

    public function assertApiConfigured(): void
    {
        $this->requireValue('merchant_id', 'RONNYPAY_MERCHANT_ID');
        $path = $this->privateKeyPath();
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('RONNYPAY_PRIVATE_KEY_PATH 未配置或私钥文件不可读');
        }
    }

    public function assertCallbackConfigured(): void
    {
        $this->requireValue('merchant_id', 'RONNYPAY_MERCHANT_ID');
        $this->requireValue('callback_secret', 'RONNYPAY_CALLBACK_SECRET');
    }

    public function apiConfigured(): bool
    {
        try {
            $this->assertApiConfigured();
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function merchantId(): string { return trim((string)$this->values['merchant_id']); }
    public function privateKeyPath(): string { return trim((string)$this->values['private_key_path']); }
    public function callbackSecret(): string { return trim((string)$this->values['callback_secret']); }
    public function notifyUrl(): string { return trim((string)$this->values['notify_url']); }
    public function walletType(): string { return trim((string)$this->values['wallet_type']); }
    public function bankCode(): string { return trim((string)$this->values['bank_code']); }
    public function baseUrl(): string { return rtrim(trim((string)$this->values['base_url']), '/'); }

    private function requireValue(string $key, string $name): void
    {
        if (trim((string)($this->values[$key] ?? '')) === '') {
            throw new RuntimeException("{$name} 未配置");
        }
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
