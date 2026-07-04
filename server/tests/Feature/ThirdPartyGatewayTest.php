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

    public function testInboundSignatureFailsWhenSecretIsMissing(): void
    {
        $gateway = new ThirdPartyGateway([
            'enabled' => true,
            'base_url' => '',
            'sign_secret' => '',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('第三方签名密钥未配置');

        $gateway->verifyInboundSignature('GET', '/api/third-party/game-accounts/3/config', '1782972000', 'anything', 1782972000);
    }

    public function testInboundSignatureFailsWhenSignatureIsInvalid(): void
    {
        $gateway = new ThirdPartyGateway([
            'enabled' => true,
            'base_url' => '',
            'sign_secret' => 'secret',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('第三方签名无效');

        $gateway->verifyInboundSignature('GET', '/api/third-party/game-accounts/3/config', '1782972000', 'bad-signature', 1782972000);
    }

    public function testInboundSignaturePassesWithExpectedSignature(): void
    {
        $gateway = new ThirdPartyGateway([
            'enabled' => true,
            'base_url' => '',
            'sign_secret' => 'secret',
        ]);
        $timestamp = '1782972000';
        $path = '/api/third-party/game-accounts/3/config';
        $signature = hash_hmac('sha256', "GET\n{$path}\n{$timestamp}", 'secret');

        $gateway->verifyInboundSignature('GET', $path, $timestamp, $signature, 1782972000);

        $this->expectNotToPerformAssertions();
    }

    public function testWebSocketTransportFailsClearlyWhenUrlIsMissing(): void
    {
        $gateway = new ThirdPartyGateway([
            'enabled' => true,
            'transport' => 'websocket',
            'ws_url' => '',
            'base_url' => '',
            'sign_secret' => 'secret',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('第三方WebSocket地址未配置');

        $gateway->startAccount(['id' => 1], [], 'password');
    }

    public function testHttpTransportFailsClearlyWhenBaseUrlIsMissing(): void
    {
        $gateway = new ThirdPartyGateway([
            'enabled' => true,
            'transport' => 'http',
            'ws_url' => '',
            'base_url' => '',
            'sign_secret' => 'secret',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('第三方HTTP接口地址未配置');

        $gateway->startAccount(['id' => 1], [], 'password');
    }
}
