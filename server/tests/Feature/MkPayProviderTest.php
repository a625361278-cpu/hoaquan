<?php

namespace tests\Feature;

use app\exception\PaymentProviderException;
use app\service\MkPayConfig;
use app\service\MkPayProvider;
use app\service\MkPaySigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tests\Support\FakeMkPayGateway;

final class MkPayProviderTest extends TestCase
{
    #[DataProvider('statusProvider')]
    public function testCallbackMapsPayStatusCodes(int $statusCode, string $expected): void
    {
        $provider = $this->provider(new FakeMkPayGateway([]));
        $payload = $this->response($statusCode);
        $payload['timestamp'] = 1700000000;
        $payload['sign'] = (new MkPaySigner())->callbackSignature($payload, 'test-secret');

        $result = $provider->parseCallback($payload);

        $this->assertSame($expected, $result['status']);
    }

    public function testCreatePrefersRedirectUrlAndNormalizesAmount(): void
    {
        $provider = $this->provider(new FakeMkPayGateway($this->response(1)));

        $result = $provider->createOrder($this->order());

        $this->assertSame('pending', $result['status']);
        $this->assertSame('149000.00', $result['total_fee']);
        $this->assertSame('https://pay.example.com/checkout/MK-001', $result['pay_url']);
    }

    public function testCreateFallsBackToQrUrl(): void
    {
        $response = $this->response(1);
        $response['redirect_url'] = '';
        $provider = $this->provider(new FakeMkPayGateway($response));

        $this->assertSame('https://pay.example.com/qr/MK-001', $provider->createOrder($this->order())['pay_url']);
    }

    public function testPayoutOnlyStatusIsRejected(): void
    {
        $provider = $this->provider(new FakeMkPayGateway($this->response(10)));
        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessage('代付专用状态码');
        $provider->createOrder($this->order());
    }

    public static function statusProvider(): array
    {
        return [
            'waiting' => [0, 'pending'],
            'processing' => [1, 'pending'],
            'success' => [2, 'success'],
            'failed' => [3, 'fail'],
            'expired' => [4, 'fail'],
            'closed' => [6, 'fail'],
            'manual review' => [7, 'unknown'],
        ];
    }

    private function provider(FakeMkPayGateway $gateway): MkPayProvider
    {
        return new MkPayProvider($this->config(), $gateway, new MkPaySigner());
    }

    private function config(): MkPayConfig
    {
        return new MkPayConfig([
            'base_url' => 'https://pay.example.com',
            'merchant_id' => 'merchant-test',
            'merchant_secret' => 'test-secret',
            'product_code' => 'VN01',
            'notify_url' => 'https://example.com/api/recharge/mkpay/notify',
        ]);
    }

    private function order(): array
    {
        return [
            'merchant_order' => 'GA001',
            'provider_order_number' => '',
            'provider_amount' => '149000',
            'total_fee' => '149000.00',
        ];
    }

    private function response(int $statusCode): array
    {
        return [
            'pay_order_id' => 'MK-001',
            'merchant_order_id' => 'GA001',
            'product_type' => 'PAY',
            'amount' => 149000,
            'currency' => 'VND',
            'redirect_url' => 'https://pay.example.com/checkout/MK-001',
            'qr_code_url' => 'https://pay.example.com/qr/MK-001',
            'status' => 'submitted',
            'status_code' => $statusCode,
        ];
    }
}
