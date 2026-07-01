<?php

namespace tests\Feature;

use app\exception\ApiException;
use app\service\ThirdPartyGateway;
use PHPUnit\Framework\TestCase;

class ThirdPartyGatewayTest extends TestCase
{
    public function testApplyConfigFailsClearlyWhenThirdPartyApiIsDisabled(): void
    {
        $gateway = new ThirdPartyGateway([
            'enabled' => false,
            'base_url' => '',
            'sign_secret' => '',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('第三方接口未启用，不能同步配置');

        $gateway->applyConfig(1, []);
    }
}
