<?php

namespace tests\Feature;

use app\exception\RonnyPayException;
use app\service\GuzzleRonnyPayGateway;
use app\service\RonnyPayConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GuzzleRonnyPayGatewayTest extends TestCase
{
    private string $privateKeyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $windowsConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (is_file($windowsConfig)) {
            $options['config'] = $windowsConfig;
        }
        $key = openssl_pkey_new($options);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privatePem, null, $options));
        $this->privateKeyPath = tempnam(sys_get_temp_dir(), 'ronnypay-gateway-');
        file_put_contents($this->privateKeyPath, $privatePem);
    }

    protected function tearDown(): void
    {
        @unlink($this->privateKeyPath);
        parent::tearDown();
    }

    public function testQueryReadsSuccessfulJsonData(): void
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'code' => 0,
            'data' => ['merchant_order' => 'GA001', 'status' => 'pending'],
        ]))]);
        $gateway = $this->gateway($mock);

        $data = $gateway->queryOrder('GA001');

        $this->assertSame('GA001', $data['merchant_order']);
        $this->assertSame('pending', $data['status']);
    }

    public function test502IsReportedAsTransientFailure(): void
    {
        $mock = new MockHandler([new Response(502, ['Content-Type' => 'application/json'], '{"code":50001,"message":"upstream"}')]);
        $gateway = $this->gateway($mock);

        try {
            $gateway->queryOrder('GA001');
            $this->fail('Expected RonnyPayException');
        } catch (RonnyPayException $e) {
            $this->assertTrue($e->isTransient());
            $this->assertSame(502, $e->httpStatus());
        }
    }

    public function testCreateSendsMomoFieldsAndSignsBankAccount(): void
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'code' => 0,
            'data' => ['merchant_order' => 'GA001', 'status' => 'pending'],
        ]))]);
        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $gateway = new GuzzleRonnyPayGateway(
            $this->config(),
            client: new Client(['handler' => $stack, 'http_errors' => false])
        );

        $gateway->createOrder([
            'merchant_order' => 'GA001',
            'total_fee' => '149000',
            'customer_name' => 'Nguyen Van A',
            'customer_mobile' => '0901234567',
            'bank_account' => '00123 payer account / A',
        ]);

        $this->assertCount(1, $history);
        $body = json_decode((string)$history[0]['request']->getBody(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('1', $body['wallet_type']);
        $this->assertSame('971025', $body['bank_code']);
        $this->assertSame('149000', $body['total_fee']);
        $this->assertSame('00123 payer account / A', $body['bank_account']);
        $this->assertNotSame('', (string)$body['sign']);
        $unsigned = $body;
        unset($unsigned['sign']);
        $privateKey = openssl_pkey_get_private(file_get_contents($this->privateKeyPath));
        self::assertNotFalse($privateKey);
        $details = openssl_pkey_get_details($privateKey);
        self::assertIsArray($details);
        $this->assertTrue((new \app\service\RonnyPaySigner())->verifyRequestSignature(
            $unsigned,
            (string)$body['sign'],
            (string)$details['key']
        ));
    }

    private function gateway(MockHandler $mock): GuzzleRonnyPayGateway
    {
        $client = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        return new GuzzleRonnyPayGateway($this->config(), client: $client);
    }

    private function config(): RonnyPayConfig
    {
        return new RonnyPayConfig([
            'enabled' => '1',
            'merchant_id' => 'merchant-test',
            'private_key_path' => $this->privateKeyPath,
            'callback_secret' => 'callback-secret',
            'notify_url' => 'https://example.com/notify',
            'wallet_type' => '1',
            'bank_code' => '971025',
            'base_url' => 'https://ronnypay.com',
        ]);
    }
}
