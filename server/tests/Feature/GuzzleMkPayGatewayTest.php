<?php

namespace tests\Feature;

use app\exception\PaymentProviderException;
use app\service\GuzzleMkPayGateway;
use app\service\MkPayConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GuzzleMkPayGatewayTest extends TestCase
{
    public function testCreateSendsExactMinimalSignedJsonWithoutCountryHeader(): void
    {
        $history = [];
        $gateway = $this->gateway(new Response(201, ['Content-Type' => 'application/json'], json_encode($this->pendingResponse())), $history);

        $gateway->createOrder(['merchant_order' => 'GA001', 'amount' => '149000']);

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $bodyText = (string)$request->getBody();
        $body = json_decode($bodyText, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame([
            'mch_id' => 'merchant-test',
            'amount' => 149000,
            'merchant_order_id' => 'GA001',
            'product_code' => 'VN01',
            'notify_url' => 'https://example.com/api/recharge/mkpay/notify',
        ], $body);
        $this->assertFalse($request->hasHeader('X-Country'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $request->getHeaderLine('X-Nonce'));
        $timestamp = $request->getHeaderLine('X-Timestamp');
        $this->assertSame(hash_hmac('sha256', $timestamp . $bodyText, 'test-secret'), $request->getHeaderLine('X-Signature'));
        $this->assertSame('/api/v1/pay', $request->getUri()->getPath());
    }

    public function testQueryUsesMerchantOrderEndpointOnSameHost(): void
    {
        $history = [];
        $gateway = $this->gateway(new Response(200, ['Content-Type' => 'application/json'], json_encode($this->pendingResponse())), $history);

        $gateway->queryOrder('GA001');

        $request = $history[0]['request'];
        $this->assertSame('/api/v1/query-by-moid', $request->getUri()->getPath());
        $this->assertSame([
            'mch_id' => 'merchant-test',
            'merchant_order_id' => 'GA001',
        ], json_decode((string)$request->getBody(), true, 32, JSON_THROW_ON_ERROR));
    }

    public function testCreateRejectsHttp200BecauseContractRequires201(): void
    {
        $history = [];
        $gateway = $this->gateway(new Response(200, ['Content-Type' => 'application/json'], json_encode($this->pendingResponse())), $history);

        $this->expectException(PaymentProviderException::class);
        $gateway->createOrder(['merchant_order' => 'GA001', 'amount' => '149000']);
    }

    private function gateway(Response $response, array &$history): GuzzleMkPayGateway
    {
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($history));
        return new GuzzleMkPayGateway(
            $this->config(),
            client: new Client(['handler' => $stack, 'http_errors' => false])
        );
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

    private function pendingResponse(): array
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
            'status_code' => 1,
        ];
    }
}
