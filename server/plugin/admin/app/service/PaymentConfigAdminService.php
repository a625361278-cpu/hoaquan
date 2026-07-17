<?php

namespace plugin\admin\app\service;

use app\service\PaymentProviderRegistry;
use app\service\PaymentCallbackIpWhitelist;
use app\service\SystemSettingService;
use app\support\I18n;
use RuntimeException;

final class PaymentConfigAdminService
{
    private SystemSettingService $settings;
    private PaymentProviderRegistry $providers;
    private string $locale;

    public function __construct(
        ?SystemSettingService $settings = null,
        ?PaymentProviderRegistry $providers = null,
        string $locale = I18n::DEFAULT_LOCALE
    ) {
        $this->settings = $settings ?? new SystemSettingService();
        $this->providers = $providers ?? new PaymentProviderRegistry();
        $this->locale = I18n::normalizeLocale($locale);
    }

    public function config(): array
    {
        $providerStatuses = [];
        foreach ($this->providers->all() as $code => $provider) {
            $configured = true;
            $error = '';
            try {
                $provider->assertCanCreateOrder();
            } catch (RuntimeException $e) {
                $configured = false;
                $error = $e->getMessage();
            }
            $providerStatuses[$code] = [
                'label' => $provider->label(),
                'configured' => $configured,
                'configuration_error' => $error,
            ];
        }
        return [
            'active_provider' => $this->settings->paymentActiveProvider(),
            'recharge_amount_vnd' => $this->settings->paymentRechargeAmountVnd(),
            'callback_allowed_ips' => $this->settings->paymentCallbackAllowedIps(),
            'providers' => $providerStatuses,
        ];
    }

    public function save(array $payload): void
    {
        $amount = $this->validateRechargeAmount($payload['payment_recharge_amount_vnd'] ?? '');
        $callbackAllowedIps = $this->validateCallbackAllowedIps($payload['payment_callback_allowed_ips'] ?? '');
        $provider = trim((string)($payload['payment_active_provider'] ?? ''));
        if (!in_array($provider, SystemSettingService::PAYMENT_PROVIDERS, true)) {
            throw new RuntimeException('支付方式配置无效');
        }
        if ($provider !== SystemSettingService::PAYMENT_PROVIDER_DISABLED) {
            $this->providers->get($provider)->assertCanCreateOrder();
        }
        $this->settings->saveSettings([
            'payment_active_provider' => $provider,
            'payment_recharge_amount_vnd' => (string)$amount,
            SystemSettingService::PAYMENT_CALLBACK_ALLOWED_IPS => $callbackAllowedIps,
        ]);
    }

    private function validateRechargeAmount(mixed $value): int
    {
        $raw = trim((string)$value);
        if (!preg_match('/^\d+$/', $raw)) {
            throw $this->invalidRechargeAmount();
        }
        $amount = (int)$raw;
        if ($amount < SystemSettingService::MIN_PAYMENT_RECHARGE_AMOUNT_VND
            || $amount > SystemSettingService::MAX_PAYMENT_RECHARGE_AMOUNT_VND) {
            throw $this->invalidRechargeAmount();
        }
        return $amount;
    }

    private function invalidRechargeAmount(): RuntimeException
    {
        return new RuntimeException(I18n::t('admin.payment_config.recharge_amount_invalid', [
            'min' => SystemSettingService::MIN_PAYMENT_RECHARGE_AMOUNT_VND,
            'max' => SystemSettingService::MAX_PAYMENT_RECHARGE_AMOUNT_VND,
        ], $this->locale));
    }

    private function validateCallbackAllowedIps(mixed $value): string
    {
        $raw = trim((string)$value);
        foreach (PaymentCallbackIpWhitelist::parse($raw) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new RuntimeException('支付回调白名单IP格式无效：' . $ip);
            }
        }
        return $raw;
    }
}
