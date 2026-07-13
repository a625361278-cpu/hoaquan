<?php

namespace app\service;

use app\exception\RonnyPayException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use support\Db;
use support\Log;

final class PaymentOrderService
{
    public const PACKAGE_CODE = 'quota_30';
    public const POINTS = '30.00';
    public const TOTAL_FEE = '149000.00';
    private const RONNYPAY_VN_TOTAL_FEE = '149000';
    public const CURRENCY = 'VND';
    private const QUERY_DELAYS_MINUTES = [1, 5, 15, 30, 60];

    public function __construct(
        private ?RonnyPayConfig $config = null,
        private ?RonnyPayGatewayInterface $gateway = null,
        private ?RonnyPaySigner $signer = null
    ) {
        $this->config ??= new RonnyPayConfig();
        $this->signer ??= new RonnyPaySigner();
        $this->gateway ??= new GuzzleRonnyPayGateway($this->config, $this->signer);
    }

    public function create(int $userId, array $input): array
    {
        $this->config->assertCanCreateOrder();
        $packageCode = trim((string)($input['package_code'] ?? ''));
        if ($packageCode !== self::PACKAGE_CODE) {
            throw new InvalidArgumentException('不支持的充值套餐');
        }
        $name = $this->requiredText($input, 'customer_name', 128, '付款人姓名');
        $mobile = $this->requiredText($input, 'customer_mobile', 64, '付款人手机号');
        $bankAccount = trim((string)($input['bank_account'] ?? ''));
        if ($bankAccount === '') {
            throw new InvalidArgumentException('MoMo付款账号不能为空');
        }
        $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('幂等键格式无效');
        }
        if (!Db::table('ga_users')->where('id', $userId)->where('status', 1)->exists()) {
            throw new InvalidArgumentException('用户不存在或已停用');
        }

        $existing = $this->findByIdempotency($userId, $idempotencyKey);
        if ($existing) {
            return $this->publicOrder($existing);
        }

        $now = date('Y-m-d H:i:s');
        $merchantOrder = $this->merchantOrder();
        try {
            Db::table('ga_payment_orders')->insert([
                'user_id' => $userId,
                'provider' => 'ronnypay',
                'package_code' => self::PACKAGE_CODE,
                'points' => self::POINTS,
                'currency' => self::CURRENCY,
                'total_fee' => self::TOTAL_FEE,
                'customer_name' => $name,
                'customer_mobile' => $mobile,
                'bank_account' => $bankAccount,
                'idempotency_key' => $idempotencyKey,
                'merchant_order' => $merchantOrder,
                'status' => 'creating',
                'country' => 'VN',
                'wallet_type' => $this->config->walletType(),
                'bank_code' => $this->config->bankCode(),
                'query_attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $e) {
            $existing = $this->findByIdempotency($userId, $idempotencyKey);
            if ($existing) {
                return $this->publicOrder($existing);
            }
            throw $e;
        }

        try {
            $data = $this->gateway->createOrder([
                'merchant_order' => $merchantOrder,
                'total_fee' => self::RONNYPAY_VN_TOTAL_FEE,
                'customer_name' => $name,
                'customer_mobile' => $mobile,
                'bank_account' => $bankAccount,
            ]);
            $this->validateProviderData($data, $merchantOrder, true);
            Db::table('ga_payment_orders')->where('merchant_order', $merchantOrder)->update([
                'provider_order_number' => trim((string)$data['order_number']),
                'pay_url' => trim((string)$data['pay_url']),
                'status' => 'pending',
                'next_query_at' => date('Y-m-d H:i:s', time() + 60),
                'last_error_code' => '',
                'last_error_message' => '',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (RonnyPayException $e) {
            $status = $e->isTransient() ? 'unknown' : 'create_failed';
            Db::table('ga_payment_orders')->where('merchant_order', $merchantOrder)->update([
                'status' => $status,
                'next_query_at' => $status === 'unknown' ? date('Y-m-d H:i:s', time() + 60) : null,
                'last_error_code' => $e->providerCode(),
                'last_error_message' => mb_substr($e->getMessage(), 0, 255),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Log::warning('RonnyPay create failed', [
                'merchant_order' => $merchantOrder,
                'status' => $status,
                'provider_code' => $e->providerCode(),
                'http_status' => $e->httpStatus(),
            ]);
        } catch (\Throwable $e) {
            Db::table('ga_payment_orders')->where('merchant_order', $merchantOrder)->update([
                'status' => 'create_failed',
                'last_error_message' => mb_substr($e->getMessage(), 0, 255),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            throw $e;
        }

        return $this->publicOrder($this->requireOrder($merchantOrder));
    }

    public function getForUser(int $userId, string $merchantOrder): array
    {
        $row = Db::table('ga_payment_orders')
            ->where('merchant_order', trim($merchantOrder))
            ->where('user_id', $userId)
            ->first();
        if (!$row) {
            throw new InvalidArgumentException('支付订单不存在');
        }
        return $this->publicOrder((array)$row);
    }

    public function handleCallback(array $parameters): void
    {
        $this->config->assertCallbackConfigured();
        if (!$this->signer->verifyCallback($parameters, $this->config->callbackSecret())) {
            Log::warning('RonnyPay callback signature invalid', [
                'merchant_order' => (string)($parameters['merchant_order'] ?? ''),
                'remote_order' => (string)($parameters['order_number'] ?? ''),
            ]);
            throw new InvalidArgumentException('回调签名无效');
        }
        $merchantOrder = trim((string)($parameters['merchant_order'] ?? ''));
        $order = $this->requireOrder($merchantOrder);
        $this->validateProviderData($parameters, $merchantOrder, false, $order);
        $status = strtolower(trim((string)$parameters['status']));
        if (!in_array($status, ['pending', 'success', 'fail'], true)) {
            throw new InvalidArgumentException('RonnyPay 回调状态无效');
        }
        if ($status === 'success') {
            $this->creditSuccess($merchantOrder, $parameters, true);
            return;
        }
        $this->updateNonSuccessStatus($merchantOrder, $status, $parameters, true);
    }

    public function query(string $merchantOrder): array
    {
        $this->config->assertApiConfigured();
        $order = $this->requireOrder($merchantOrder);
        if ((string)$order['status'] === 'success') {
            return $this->publicOrder($order);
        }
        try {
            $data = $this->gateway->queryOrder($merchantOrder);
            $this->validateProviderData($data, $merchantOrder, false, $order);
            $status = strtolower(trim((string)($data['status'] ?? '')));
            if (!in_array($status, ['pending', 'success', 'fail'], true)) {
                throw new RonnyPayException('RonnyPay 查单状态无效', false);
            }
            if ($status === 'success') {
                $this->creditSuccess($merchantOrder, $data, false);
            } else {
                $this->updateNonSuccessStatus($merchantOrder, $status, $data, false);
            }
        } catch (RonnyPayException $e) {
            $this->scheduleNextQuery($merchantOrder, $e->providerCode(), $e->getMessage());
            Log::warning('RonnyPay query failed', [
                'merchant_order' => $merchantOrder,
                'provider_code' => $e->providerCode(),
                'http_status' => $e->httpStatus(),
            ]);
        }
        return $this->publicOrder($this->requireOrder($merchantOrder));
    }

    public function reconcileDue(int $limit = 50): int
    {
        $this->config->assertApiConfigured();
        $orders = Db::table('ga_payment_orders')
            ->whereIn('status', ['pending', 'unknown'])
            ->whereNotNull('next_query_at')
            ->where('next_query_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('next_query_at')
            ->limit(max(1, min(50, $limit)))
            ->pluck('merchant_order')
            ->all();
        foreach ($orders as $merchantOrder) {
            $this->query((string)$merchantOrder);
        }
        return count($orders);
    }

    public function publicOrder(array|object $order): array
    {
        $row = (array)$order;
        return [
            'merchant_order' => (string)$row['merchant_order'],
            'provider_order_number' => (string)($row['provider_order_number'] ?? ''),
            'package_code' => (string)$row['package_code'],
            'points' => (string)$row['points'],
            'currency' => (string)$row['currency'],
            'total_fee' => (string)$row['total_fee'],
            'status' => (string)$row['status'],
            'pay_url' => (string)($row['pay_url'] ?? ''),
            'wallet_type' => (string)($row['wallet_type'] ?? ''),
            'bank_code' => (string)($row['bank_code'] ?? ''),
            'created_at' => (string)$row['created_at'],
            'credited_at' => (string)($row['credited_at'] ?? ''),
            'last_error' => (string)($row['last_error_message'] ?? ''),
        ];
    }

    private function creditSuccess(string $merchantOrder, array $providerData, bool $fromCallback): void
    {
        Db::connection()->transaction(function () use ($merchantOrder, $providerData, $fromCallback): void {
            $order = Db::table('ga_payment_orders')->where('merchant_order', $merchantOrder)->lockForUpdate()->first();
            if (!$order) {
                throw new InvalidArgumentException('支付订单不存在');
            }
            if ((string)$order->status === 'success' && $order->credited_at !== null) {
                return;
            }
            $user = Db::table('ga_users')->where('id', (int)$order->user_id)->lockForUpdate()->first();
            if (!$user || (int)$user->status !== 1) {
                throw new RuntimeException('支付订单对应用户不存在或已停用');
            }
            $hasTransaction = Db::table('ga_user_point_transactions')
                ->where('type', 'recharge')
                ->where('related_payment_order_id', (int)$order->id)
                ->exists();
            if ($hasTransaction) {
                throw new RuntimeException('支付订单已有充值流水但订单尚未标记入账，数据状态异常');
            }
            $now = date('Y-m-d H:i:s');
            $balanceAfter = $this->formatCents(
                $this->decimalToCents((string)$user->balance) + $this->decimalToCents((string)$order->points)
            );
            Db::table('ga_users')->where('id', (int)$order->user_id)->update([
                'balance' => $balanceAfter,
                'updated_at' => $now,
            ]);
            Db::table('ga_user_point_transactions')->insert([
                'user_id' => (int)$order->user_id,
                'type' => 'recharge',
                'amount' => (string)$order->points,
                'balance_after' => $balanceAfter,
                'description' => 'RonnyPay 充值 ' . (string)$order->merchant_order,
                'related_user_id' => null,
                'related_role_id' => '',
                'related_payment_order_id' => (int)$order->id,
                'ip_address' => '',
                'created_at' => $now,
            ]);
            Db::table('ga_payment_orders')->where('id', (int)$order->id)->update([
                'status' => 'success',
                'provider_order_number' => trim((string)($providerData['order_number'] ?? $order->provider_order_number)),
                'utr' => trim((string)($providerData['utr'] ?? $order->utr)),
                'credited_at' => $now,
                'notified_at' => $fromCallback ? $now : $order->notified_at,
                'last_queried_at' => $fromCallback ? $order->last_queried_at : $now,
                'next_query_at' => null,
                'last_error_code' => '',
                'last_error_message' => '',
                'updated_at' => $now,
            ]);
        });
    }

    private function updateNonSuccessStatus(string $merchantOrder, string $status, array $data, bool $fromCallback): void
    {
        $order = $this->requireOrder($merchantOrder);
        if ((string)$order['status'] === 'success') {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $updates = [
            'status' => $status,
            'provider_order_number' => trim((string)($data['order_number'] ?? $order['provider_order_number'] ?? '')),
            'utr' => trim((string)($data['utr'] ?? $order['utr'] ?? '')),
            'updated_at' => $now,
        ];
        if ($fromCallback) {
            $updates['notified_at'] = $now;
        } else {
            $updates['last_queried_at'] = $now;
            $updates['query_attempts'] = (int)$order['query_attempts'] + 1;
        }
        $updates['next_query_at'] = $status === 'pending'
            ? $this->nextQueryAt((int)($updates['query_attempts'] ?? $order['query_attempts']))
            : null;
        Db::table('ga_payment_orders')->where('merchant_order', $merchantOrder)->where('status', '<>', 'success')->update($updates);
    }

    private function scheduleNextQuery(string $merchantOrder, string $code, string $message): void
    {
        $order = $this->requireOrder($merchantOrder);
        if ((string)$order['status'] === 'success') {
            return;
        }
        $attempts = (int)$order['query_attempts'] + 1;
        Db::table('ga_payment_orders')->where('merchant_order', $merchantOrder)->where('status', '<>', 'success')->update([
            'status' => 'unknown',
            'query_attempts' => $attempts,
            'last_queried_at' => date('Y-m-d H:i:s'),
            'next_query_at' => $this->nextQueryAt($attempts),
            'last_error_code' => $code,
            'last_error_message' => mb_substr($message, 0, 255),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function nextQueryAt(int $attempts): string
    {
        $index = max(0, min(count(self::QUERY_DELAYS_MINUTES) - 1, $attempts));
        return date('Y-m-d H:i:s', time() + self::QUERY_DELAYS_MINUTES[$index] * 60);
    }

    private function validateProviderData(array $data, string $merchantOrder, bool $creation, ?array $localOrder = null): void
    {
        if (trim((string)($data['merchant_id'] ?? '')) !== $this->config->merchantId()) {
            throw new RonnyPayException('RonnyPay 响应商户号不一致', false);
        }
        if (trim((string)($data['merchant_order'] ?? '')) !== $merchantOrder) {
            throw new RonnyPayException('RonnyPay 响应商户订单号不一致', false);
        }
        $expectedAmount = $localOrder['total_fee'] ?? self::TOTAL_FEE;
        if ($this->normalizeAmount($data['total_fee'] ?? '') !== $this->normalizeAmount($expectedAmount)) {
            throw new RonnyPayException('RonnyPay 响应金额不一致', false);
        }
        $remoteOrder = trim((string)($data['order_number'] ?? ''));
        if ($remoteOrder === '') {
            throw new RonnyPayException('RonnyPay 响应缺少平台订单号', false);
        }
        if ($localOrder && !empty($localOrder['provider_order_number']) && !hash_equals((string)$localOrder['provider_order_number'], $remoteOrder)) {
            throw new RonnyPayException('RonnyPay 平台订单号不一致', false);
        }
        if ($creation) {
            if (strtolower(trim((string)($data['status'] ?? ''))) !== 'pending') {
                throw new RonnyPayException('RonnyPay 下单响应状态不是 pending', false);
            }
            $payUrl = trim((string)($data['pay_url'] ?? ''));
            if (filter_var($payUrl, FILTER_VALIDATE_URL) === false || strtolower((string)parse_url($payUrl, PHP_URL_SCHEME)) !== 'https') {
                throw new RonnyPayException('RonnyPay 下单响应支付链接无效', false);
            }
        }
    }

    private function normalizeAmount(mixed $amount): string
    {
        $value = trim((string)$amount);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new RonnyPayException('RonnyPay 金额格式无效', false);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        return ($whole === '' ? '0' : $whole) . '.' . str_pad($fraction, 2, '0');
    }

    private function decimalToCents(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new RuntimeException('点数金额格式异常：' . $value);
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $cents = (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
        return $negative ? -$cents : $cents;
    }

    private function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    private function requiredText(array $input, string $key, int $maxLength, string $label): string
    {
        $value = trim((string)($input[$key] ?? ''));
        if ($value === '' || mb_strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new InvalidArgumentException("{$label}格式无效");
        }
        return $value;
    }

    private function merchantOrder(): string
    {
        return 'GA' . date('YmdHis') . strtoupper(bin2hex(random_bytes(8)));
    }

    private function findByIdempotency(int $userId, string $key): ?array
    {
        $row = Db::table('ga_payment_orders')->where('user_id', $userId)->where('idempotency_key', $key)->first();
        return $row ? (array)$row : null;
    }

    private function requireOrder(string $merchantOrder): array
    {
        $row = Db::table('ga_payment_orders')->where('merchant_order', trim($merchantOrder))->first();
        if (!$row) {
            throw new InvalidArgumentException('支付订单不存在');
        }
        return (array)$row;
    }
}
