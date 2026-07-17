<?php

namespace app\service;

use RuntimeException;

final class PaymentProviderRegistry
{
    /** @var array<string, PaymentProviderInterface> */
    private array $providers = [];

    public function __construct(?array $providers = null)
    {
        $providers ??= [new RonnyPayProvider(), new MkPayProvider()];
        foreach ($providers as $provider) {
            if (!$provider instanceof PaymentProviderInterface) {
                throw new RuntimeException('支付通道注册项类型无效');
            }
            $code = $provider->code();
            if ($code === '' || isset($this->providers[$code])) {
                throw new RuntimeException('支付通道代码为空或重复：' . $code);
            }
            $this->providers[$code] = $provider;
        }
    }

    public function get(string $code): PaymentProviderInterface
    {
        $provider = $this->providers[$code] ?? null;
        if (!$provider) {
            throw new RuntimeException('不支持的支付通道：' . $code);
        }
        return $provider;
    }

    /** @return array<string, PaymentProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }
}
