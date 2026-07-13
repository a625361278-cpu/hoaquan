<?php

namespace tests\Feature;

use app\exception\RonnyPayException;
use app\service\PaymentOrderService;
use app\service\RonnyPayConfig;
use app\service\RonnyPaySigner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\Support\FakeRonnyPayGateway;

final class PaymentOrderServiceTest extends TestCase
{
    private $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Db::connection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testCreateIsIdempotentAndNeverTrustsClientAmount(): void
    {
        $userId = $this->createUser('0.00');
        $gateway = new FakeRonnyPayGateway($this->pendingCreateData());
        $service = $this->service($gateway);
        $input = $this->createInput() + ['total_fee' => '1.00', 'points' => 99999];

        $first = $service->create($userId, $input);
        $second = $service->create($userId, $input);

        $this->assertSame($first['merchant_order'], $second['merchant_order']);
        $this->assertSame('149000.00', $first['total_fee']);
        $this->assertSame('30.00', $first['points']);
        $this->assertSame(1, $gateway->createCalls);
        $this->assertSame('149000', $gateway->lastCreateOrder['total_fee']);
        $this->assertSame('00123 payer account / A', $gateway->lastCreateOrder['bank_account']);
        $this->assertSame(1, Db::table('ga_payment_orders')->where('user_id', $userId)->count());
        $this->assertSame('00123 payer account / A', (string)Db::table('ga_payment_orders')->where('user_id', $userId)->value('bank_account'));
    }

    public function testCreateRejectsEmptyBankAccountBeforeWritingOrder(): void
    {
        $userId = $this->createUser('0.00');
        $input = $this->createInput();
        $input['bank_account'] = " \t ";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MoMo付款账号不能为空');
        try {
            $this->service(new FakeRonnyPayGateway($this->pendingCreateData()))->create($userId, $input);
        } finally {
            $this->assertSame(0, Db::table('ga_payment_orders')->where('user_id', $userId)->count());
        }
    }

    public function testIdempotentRetryDoesNotReplaceOriginalBankAccount(): void
    {
        $userId = $this->createUser('0.00');
        $gateway = new FakeRonnyPayGateway($this->pendingCreateData());
        $service = $this->service($gateway);
        $input = $this->createInput();

        $first = $service->create($userId, $input);
        $input['bank_account'] = 'different-account';
        $second = $service->create($userId, $input);

        $this->assertSame($first['merchant_order'], $second['merchant_order']);
        $this->assertSame(1, $gateway->createCalls);
        $this->assertSame('00123 payer account / A', (string)Db::table('ga_payment_orders')->where('merchant_order', $first['merchant_order'])->value('bank_account'));
    }

    public function testCreateRejectsMissingCallbackSecretBeforeWritingOrder(): void
    {
        $userId = $this->createUser('0.00');
        $config = new RonnyPayConfig([
            'enabled' => '1',
            'merchant_id' => 'merchant-test',
            'private_key_path' => __FILE__,
            'callback_secret' => '',
            'notify_url' => 'https://example.com/api/recharge/ronnypay/notify',
            'wallet_type' => '',
            'bank_code' => '',
            'base_url' => 'https://ronnypay.com',
        ]);
        $service = new PaymentOrderService($config, new FakeRonnyPayGateway($this->pendingCreateData()), new RonnyPaySigner());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('RONNYPAY_CALLBACK_SECRET 未配置');
        try {
            $service->create($userId, $this->createInput());
        } finally {
            $this->assertSame(0, Db::table('ga_payment_orders')->where('user_id', $userId)->count());
        }
    }

    public function testTransientCreateFailureStaysUnknownForOriginalOrder(): void
    {
        $userId = $this->createUser('0.00');
        $gateway = new FakeRonnyPayGateway(new RonnyPayException('timeout', true, '', 0));

        $order = $this->service($gateway)->create($userId, $this->createInput());

        $this->assertSame('unknown', $order['status']);
        $this->assertSame(1, Db::table('ga_payment_orders')->where('merchant_order', $order['merchant_order'])->count());
        $this->assertNotNull(Db::table('ga_payment_orders')->where('merchant_order', $order['merchant_order'])->value('next_query_at'));
    }

    public function testMalformedCreateResponseBecomesCreateFailed(): void
    {
        $userId = $this->createUser('0.00');
        $response = $this->pendingCreateData();
        $response['merchant_id'] = 'another-merchant';

        $order = $this->service(new FakeRonnyPayGateway($response))->create($userId, $this->createInput());

        $this->assertSame('create_failed', $order['status']);
        $this->assertSame('', $order['pay_url']);
    }

    public function testDuplicateSuccessCallbacksCreditExactlyOnce(): void
    {
        $userId = $this->createUser('2.00');
        $service = $this->service(new FakeRonnyPayGateway($this->pendingCreateData()));
        $order = $service->create($userId, $this->createInput());
        $callback = $this->signedCallback($order);

        $service->handleCallback($callback);
        $service->handleCallback($callback);

        $this->assertSame('32.00', (string)Db::table('ga_users')->where('id', $userId)->value('balance'));
        $this->assertSame(1, Db::table('ga_user_point_transactions')->where('user_id', $userId)->where('type', 'recharge')->count());
        $this->assertSame('success', (string)Db::table('ga_payment_orders')->where('merchant_order', $order['merchant_order'])->value('status'));
    }

    public function testSuccessNeverRegressesOnLaterFailCallback(): void
    {
        $userId = $this->createUser('0.00');
        $service = $this->service(new FakeRonnyPayGateway($this->pendingCreateData()));
        $order = $service->create($userId, $this->createInput());
        $service->handleCallback($this->signedCallback($order));
        $service->handleCallback($this->signedCallback($order, 'fail'));

        $this->assertSame('success', (string)Db::table('ga_payment_orders')->where('merchant_order', $order['merchant_order'])->value('status'));
        $this->assertSame('30.00', (string)Db::table('ga_users')->where('id', $userId)->value('balance'));
    }

    public function testQueryConfirmedSuccessUsesSameCreditTransaction(): void
    {
        $userId = $this->createUser('0.00');
        $gateway = new FakeRonnyPayGateway($this->pendingCreateData(), [
            'merchant_id' => 'merchant-test',
            'order_number' => 'RP-001',
            'status' => 'success',
            'total_fee' => '149000',
            'utr' => 'UTR-1',
        ]);
        $service = $this->service($gateway);
        $order = $service->create($userId, $this->createInput());

        $queried = $service->query($order['merchant_order']);

        $this->assertSame('success', $queried['status']);
        $this->assertSame('30.00', (string)Db::table('ga_users')->where('id', $userId)->value('balance'));
        $this->assertSame(1, $gateway->queryCalls);
    }

    public function testQuerySuccessThenCallbackStillCreditsOnce(): void
    {
        $userId = $this->createUser('0.00');
        $gateway = new FakeRonnyPayGateway($this->pendingCreateData(), [
            'merchant_id' => 'merchant-test',
            'order_number' => 'RP-001',
            'status' => 'success',
            'total_fee' => '149000.00',
        ]);
        $service = $this->service($gateway);
        $order = $service->create($userId, $this->createInput());

        $service->query($order['merchant_order']);
        $service->handleCallback($this->signedCallback($order));

        $this->assertSame('30.00', (string)Db::table('ga_users')->where('id', $userId)->value('balance'));
        $this->assertSame(1, Db::table('ga_user_point_transactions')->where('user_id', $userId)->where('type', 'recharge')->count());
    }

    public function testOtherUserCannotReadOrder(): void
    {
        $ownerId = $this->createUser('0.00');
        $otherId = $this->createUser('0.00');
        $service = $this->service(new FakeRonnyPayGateway($this->pendingCreateData()));
        $order = $service->create($ownerId, $this->createInput());

        $this->expectException(InvalidArgumentException::class);
        $service->getForUser($otherId, $order['merchant_order']);
    }

    private function service(FakeRonnyPayGateway $gateway): PaymentOrderService
    {
        return new PaymentOrderService($this->config(), $gateway, new RonnyPaySigner());
    }

    private function config(): RonnyPayConfig
    {
        return new RonnyPayConfig([
            'enabled' => '1',
            'merchant_id' => 'merchant-test',
            'private_key_path' => __FILE__,
            'callback_secret' => 'callback-secret',
            'notify_url' => 'https://example.com/api/recharge/ronnypay/notify',
            'wallet_type' => 'wallet-test',
            'bank_code' => 'bank-test',
            'base_url' => 'https://ronnypay.com',
        ]);
    }

    private function pendingCreateData(): array
    {
        return [
            'merchant_id' => 'merchant-test',
            'order_number' => 'RP-001',
            'status' => 'pending',
            'pay_url' => 'https://ronnypay.com/pay/RP-001',
        ];
    }

    private function createInput(): array
    {
        return [
            'package_code' => 'quota_30',
            'customer_name' => 'Nguyen Van A',
            'customer_mobile' => '0901234567',
            'bank_account' => '  00123 payer account / A  ',
            'idempotency_key' => 'idem_' . bin2hex(random_bytes(8)),
        ];
    }

    private function signedCallback(array $order, string $status = 'success'): array
    {
        $parameters = [
            'merchant_id' => 'merchant-test',
            'merchant_order' => $order['merchant_order'],
            'order_number' => 'RP-001',
            'total_fee' => '149000.00',
            'status' => $status,
            'utr' => 'UTR-1',
        ];
        $parameters['sign'] = (new RonnyPaySigner())->callbackSignature($parameters, 'callback-secret');
        return $parameters;
    }

    private function createUser(string $balance): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        return (int)Db::table('ga_users')->insertGetId([
            'account' => 'payment_' . $suffix,
            'email' => 'payment_' . $suffix . '@example.com',
            'nickname' => 'payment-' . $suffix,
            'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
            'balance' => $balance,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
