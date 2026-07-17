<?php

namespace app\service;

use RuntimeException;

final class MkPayConfig
{
    public function __construct(private ?array $values = null)
    {
        $this->values ??= [
            'base_url' => app_env('MKPAY_BASE_URL', 'https://pay.mkpay8888.com'),
            'merchant_id' => app_env('MKPAY_MERCHANT_ID', ''),
            'merchant_secret' => app_env('MKPAY_MERCHANT_SECRET', ''),
            'product_code' => app_env('MKPAY_PRODUCT_CODE', 'VN01'),
            'notify_url' => app_env('MKPAY_NOTIFY_URL', ''),
        ];
    }

    public function assertCanCreateOrder(): void
    {
        $this->assertApiConfigured();
        $this->requireValue('product_code', 'MKPAY_PRODUCT_CODE');
        $this->requireValue('notify_url', 'MKPAY_NOTIFY_URL');
        if (!$this->isHttpsUrl($this->notifyUrl())) {
            throw new RuntimeException('MKPAY_NOTIFY_URL 必须是有效的 HTTPS 地址');
        }
    }

    public function assertApiConfigured(): void
    {
        $this->requireValue('merchant_id', 'MKPAY_MERCHANT_ID');
        $this->requireValue('merchant_secret', 'MKPAY_MERCHANT_SECRET');
        if (!$this->isHttpsUrl($this->baseUrl())) {
            throw new RuntimeException('MKPAY_BASE_URL 必须是有效的 HTTPS 地址');
        }
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

    public function baseUrl(): string { return rtrim(trim((string)$this->values['base_url']), '/'); }
    public function merchantId(): string { return trim((string)$this->values['merchant_id']); }
    public function merchantSecret(): string { return trim((string)$this->values['merchant_secret']); }
    public function productCode(): string { return trim((string)$this->values['product_code']); }
    public function notifyUrl(): string { return trim((string)$this->values['notify_url']); }

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
