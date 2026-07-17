<?php

namespace app\service;

use app\exception\PaymentProviderException;

final class PaymentAmount
{
    public static function normalize(mixed $amount, string $providerLabel): string
    {
        $value = trim((string)$amount);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new PaymentProviderException("{$providerLabel} 金额格式无效", false);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        return ($whole === '' ? '0' : $whole) . '.' . str_pad($fraction, 2, '0');
    }
}
