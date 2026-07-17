<?php

namespace tests\Feature;

use app\service\PaymentProviderInterface;
use app\service\PaymentProviderRegistry;
use app\service\SystemSettingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use plugin\admin\app\service\PaymentConfigAdminService;
use RuntimeException;

final class PaymentConfigAdminServiceTest extends TestCase
{
    public function testConfigReturnsReadinessActiveProviderAndRechargeAmount(): void
    {
        $service = new PaymentConfigAdminService($this->settings('mkpay'), new PaymentProviderRegistry([
            $this->provider('ronnypay', true),
            $this->provider('mkpay', false),
        ]));

        $config = $service->config();

        $this->assertSame('mkpay', $config['active_provider']);
        $this->assertSame(149000, $config['recharge_amount_vnd']);
        $this->assertSame('203.0.113.10', $config['callback_allowed_ips']);
        $this->assertTrue($config['providers']['ronnypay']['configured']);
        $this->assertFalse($config['providers']['mkpay']['configured']);
        $this->assertArrayNotHasKey('merchant_secret', $config['providers']['mkpay']);
    }

    public function testSaveAllowsDisabledAndConfiguredProvider(): void
    {
        $settings = $this->settings('disabled');
        $service = new PaymentConfigAdminService($settings, new PaymentProviderRegistry([
            $this->provider('ronnypay', true),
            $this->provider('mkpay', true),
        ]));

        $service->save([
            'payment_active_provider' => 'disabled',
            'payment_recharge_amount_vnd' => '149000',
            'payment_callback_allowed_ips' => "203.0.113.10\n198.51.100.7",
        ]);
        $this->assertSame('disabled', $settings->saved['payment_active_provider']);
        $this->assertSame('149000', $settings->saved['payment_recharge_amount_vnd']);
        $this->assertSame("203.0.113.10\n198.51.100.7", $settings->saved['payment_callback_allowed_ips']);
        $service->save(['payment_active_provider' => 'mkpay', 'payment_recharge_amount_vnd' => '200000', 'payment_callback_allowed_ips' => '']);
        $this->assertSame('mkpay', $settings->saved['payment_active_provider']);
        $this->assertSame('200000', $settings->saved['payment_recharge_amount_vnd']);
        $this->assertSame('', $settings->saved['payment_callback_allowed_ips']);
    }

    public function testSaveRejectsUnconfiguredProvider(): void
    {
        $service = new PaymentConfigAdminService($this->settings('disabled'), new PaymentProviderRegistry([
            $this->provider('mkpay', false),
        ]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('配置不完整');
        $service->save(['payment_active_provider' => 'mkpay', 'payment_recharge_amount_vnd' => '149000']);
    }

    #[DataProvider('invalidRechargeAmountProvider')]
    public function testSaveRejectsInvalidRechargeAmount(string $value): void
    {
        $service = new PaymentConfigAdminService($this->settings('disabled'), new PaymentProviderRegistry([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('充值金额必须是1至999999999的VND整数');
        $service->save(['payment_active_provider' => 'disabled', 'payment_recharge_amount_vnd' => $value]);
    }

    public static function invalidRechargeAmountProvider(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'negative' => ['-1'],
            'decimal' => ['149000.50'],
            'above maximum' => ['1000000000'],
            'text' => ['149k'],
        ];
    }

    #[DataProvider('invalidCallbackAllowedIpsProvider')]
    public function testSaveRejectsInvalidCallbackAllowedIps(string $value): void
    {
        $service = new PaymentConfigAdminService($this->settings('disabled'), new PaymentProviderRegistry([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('支付回调白名单IP格式无效');
        $service->save([
            'payment_active_provider' => 'disabled',
            'payment_recharge_amount_vnd' => '149000',
            'payment_callback_allowed_ips' => $value,
        ]);
    }

    public static function invalidCallbackAllowedIpsProvider(): array
    {
        return [
            'text' => ['not-an-ip'],
            'cidr' => ['203.0.113.0/24'],
            'mixed' => ["203.0.113.10\nbad-ip"],
        ];
    }

    private function settings(string $active): SystemSettingService
    {
        return new class($active) extends SystemSettingService {
            public array $saved = [];
            public function __construct(private string $active) {}
            public function paymentActiveProvider(): string { return $this->active; }
            public function paymentRechargeAmountVnd(): int { return 149000; }
            public function paymentCallbackAllowedIps(): string { return '203.0.113.10'; }
            public function saveSettings(array $settings): void { $this->saved = $settings; }
        };
    }

    private function provider(string $code, bool $configured): PaymentProviderInterface
    {
        return new class($code, $configured) implements PaymentProviderInterface {
            public function __construct(private string $code, private bool $configured) {}
            public function code(): string { return $this->code; }
            public function label(): string { return $this->code; }
            public function assertCanCreateOrder(): void { if (!$this->configured) throw new RuntimeException('配置不完整'); }
            public function apiConfigured(): bool { return $this->configured; }
            public function orderMetadata(): array { return []; }
            public function createOrder(array $order): array { return []; }
            public function queryOrder(array $order): array { return []; }
            public function parseCallback(array $parameters): array { return []; }
        };
    }
}
