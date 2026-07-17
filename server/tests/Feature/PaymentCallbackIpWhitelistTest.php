<?php

namespace tests\Feature;

use app\service\PaymentCallbackIpWhitelist;
use app\service\SystemSettingService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PaymentCallbackIpWhitelistTest extends TestCase
{
    public function testEmptyWhitelistAllowsAnyCallbackIp(): void
    {
        $service = new PaymentCallbackIpWhitelist($this->settings(''));

        $service->assertAllowed('203.0.113.10');

        $this->expectNotToPerformAssertions();
    }

    public function testConfiguredWhitelistAllowsMultipleDelimitedIps(): void
    {
        $service = new PaymentCallbackIpWhitelist($this->settings("203.0.113.10, 198.51.100.7\n2001:db8::1"));

        $service->assertAllowed('198.51.100.7');
        $service->assertAllowed('2001:db8::1');

        $this->expectNotToPerformAssertions();
    }

    public function testConfiguredWhitelistRejectsUnexpectedCallbackIp(): void
    {
        $service = new PaymentCallbackIpWhitelist($this->settings('203.0.113.10'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('支付回调IP不在白名单');

        $service->assertAllowed('198.51.100.7');
    }

    public function testMalformedWhitelistExposesConfigurationError(): void
    {
        $service = new PaymentCallbackIpWhitelist($this->settings('203.0.113.10,not-an-ip'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('支付回调白名单IP配置异常');

        $service->assertAllowed('203.0.113.10');
    }

    private function settings(string $allowedIps): SystemSettingService
    {
        return new class($allowedIps) extends SystemSettingService {
            public function __construct(private string $allowedIps) {}
            public function paymentCallbackAllowedIps(): string { return $this->allowedIps; }
        };
    }
}
