<?php

namespace app\service;

use InvalidArgumentException;
use RuntimeException;

final class PaymentCallbackIpWhitelist
{
    private SystemSettingService $settings;

    public function __construct(?SystemSettingService $settings = null)
    {
        $this->settings = $settings ?? new SystemSettingService();
    }

    public function assertAllowed(string $requestIp): void
    {
        $allowedIps = self::parse($this->settings->paymentCallbackAllowedIps());
        if ($allowedIps === []) {
            return;
        }

        foreach ($allowedIps as $allowedIp) {
            if (!filter_var($allowedIp, FILTER_VALIDATE_IP)) {
                throw new RuntimeException('支付回调白名单IP配置异常：' . $allowedIp);
            }
        }

        $requestIp = trim($requestIp);
        if (!filter_var($requestIp, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('支付回调请求IP异常：' . $requestIp);
        }

        if (!in_array($requestIp, $allowedIps, true)) {
            throw new InvalidArgumentException('支付回调IP不在白名单');
        }
    }

    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,，;；|]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_map('trim', $parts ?: []));
    }
}
