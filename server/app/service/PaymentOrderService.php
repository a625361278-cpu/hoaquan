<?php

namespace app\service;

use app\exception\PaymentProviderException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use support\Db;
use support\Log;

final class PaymentOrderService
{
    public const PACKAGE_CODE = 'quota_30';
    public const POINTS = '30.00';
    public const CURRENCY = 'VND';
    private const QUERY_DELAYS_MINUTES = [1, 5, 15, 30, 60];

    private SystemSettingService $settings;
    private PaymentProviderRegistry $providers;

    public function __construct(
        ?SystemSettingService $settings = null,
        ?PaymentProviderRegistry $providers = null
    ) {
        $this->settings = $settings ?? new SystemSettingService();
        $this->providers = $providers ?? new PaymentProviderRegistry();
    }

    public function config(): array
    {
        $providerCode = $this->settings->paymentActiveProvider();
        $amounts = $this->configuredAmounts();
        $enabled = false;
        if ($providerCode !== SystemSettingService::PAYMENT_PROVIDER_DISABLED) {
            try {
                $this->providers->get($providerCode)->assertCanCreateOrder();
                $enabled = true;
            } catch (RuntimeException) {
                $enabled = false;
            }
        }
        return [
            'enabled' => $enabled,
            'provider' => $providerCode,
            'package' => [
                'code' => self::PACKAGE_CODE,
                'points' => self::POINTS,
                'currency' => self::CURRENCY,
                'total_fee' => $amounts['total_fee'],
            ],
        ];
    }

    public function create(int $userId, array $input): array
    {
        $packageCode = trim((string)($input['package_code'] ?? ''));
        if ($packageCode !== self::PACKAGE_CODE) {
            throw new InvalidArgumentException('不支持的充值套餐');
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

        $amounts = $this->configuredAmounts();
        $providerCode = $this->settings->paymentActiveProvider();
        if ($providerCode === SystemSettingService::PAYMENT_PROVIDER_DISABLED) {
            throw new RuntimeException('当前未启用支付方式');
        }
        $provider = $this->providers->get($providerCode);
        $provider->assertCanCreateOrder();
        $metadata = $provider->orderMetadata();
        $requiresPayerInfo = $providerCode === SystemSettingService::PAYMENT_PROVIDER_RONNYPAY;
        [$name, $mobile, $bankAccount] = $this->payerSnapshot($input, $requiresPayerInfo);

        $now = date('Y-m-d H:i:s');
        $merchantOrder = $this->merchantOrder();
        try {
            Db::table('ga_payment_orders')->insert([
                'user_id' => $userId,
                'provider' => $providerCode,
                'package_code' => self::PACKAGE_CODE,
                'points' => self::POINTS,
                'currency' => self::CURRENCY,
                'total_fee' => $amounts['total_fee'],
                'customer_name' => $name,
                'customer_mobile' => $mobile,
                'bank_account' => $bankAccount,
                'idempotency_key' => $idempotencyKey,
                'merchant_order' => $merchantOrder,
                'status' => 'creating',
                'country' => 'VN',
                'product_code' => (string)($metadata['product_code'] ?? ''),
                'wallet_type' => (string)($metadata['wallet_type'] ?? ''),
                'bank_code' => (string)($metadata['bank_code'] ?? ''),
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

        $orderContext = [
            'merchant_order' => $merchantOrder,
            'provider_order_number' => '',
            'total_fee' => $amounts['total_fee'],
            'provider_amount' => $amounts['provider_amount'],
            'customer_name' => $name,
            'customer_mobile' => $mobile,
            'bank_account' => $bankAccount,
        ];
        try {
            $result = $provider->createOrder($orderContext);
            $this->assertResultMatchesOrder($result, $this->requireOrder($merchantOrder));
            $this->storeCreationResult($merchantOrder, $result);
        } catch (PaymentProviderException $e) {
            $status = $e->isTransient() ? 'unknown' : 'create_failed';
            Db::table('ga_payment_orders')->where('merchant_order', $merchantOrder)->update([
                'status' => $status,
                'next_query_at' => $status === 'unknown' ? date('Y-m-d H:i:s', time() + 60) : null,
                'last_error_code' => $e->providerCode(),
                'last_error_message' => mb_substr($e->getMessage(), 0, 255),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Log::warning('Payment create failed', [
                'provider' => $providerCode,
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

    private function configuredAmounts(): array
    {
        $providerAmount = (string)$this->settings->paymentRechargeAmountVnd();
        return [
            'total_fee' => $providerAmount . '.00',
            'provider_amount' => $providerAmount,
        ];
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

    public function handleCallback(string $providerCode, array $parameters): void
    {
        $provider = $this->providers->get($providerCode);
        $result = $provider->parseCallback($parameters);
        $merchantOrder = trim((string)($result['merchant_order'] ?? ''));
        $order = $this->requireOrder($merchantOrder);
        if ((string)$order['provider'] !== $providerCode) {
            throw new InvalidArgumentException('回调支付通道与订单不一致');
        }
        $this->assertResultMatchesOrder($result, $order);
        $this->applyResult($merchantOrder, $result, true);
    }

    public function query(string $merchantOrder): array
    {
        $order = $this->requireOrder($merchantOrder);
        if ((string)$order['status'] === 'success') {
            return $this->publicOrder($order);
        }
        $providerCode = (string)$order['provider'];
        try {
            $result = $this->providers->get($providerCode)->queryOrder($order);
            $this->assertResultMatchesOrder($result, $order);
            $this->applyResult($merchantOrder, $result, false);
        } catch (PaymentProviderException $e) {
            $this->scheduleNextQuery($merchantOrder, $e->providerCode(), $e->getMessage());
            Log::warning('Payment query failed', [
                'provider' => $providerCode,
                'merchant_order' => $merchantOrder,
                'provider_code' => $e->providerCode(),
                'http_status' => $e->httpStatus(),
            ]);
        } catch (RuntimeException $e) {
            $this->scheduleNextQuery($merchantOrder, 'configuration_error', $e->getMessage());
            Log::error('Payment query configuration failed', [
                'provider' => $providerCode,
                'merchant_order' => $merchantOrder,
                'message' => $e->getMessage(),
            ]);
        }
        return $this->publicOrder($this->requireOrder($merchantOrder));
    }

    public function reconcileDue(int $limit = 50): int
    {
        $orders = Db::table('ga_payment_orders')
            ->whereIn('status', ['pending', 'unknown'])
            ->whereNotNull('next_query_at')
            ->where('next_query_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('next_query_at')
            ->limit(max(1, min(50, $limit)))
            ->pluck('merchant_order')
            ->all();
        foreach ($orders as $merchantOrder) {
            try {
                $this->query((string)$merchantOrder);
            } catch (\Throwable $e) {
                Log::error('Payment reconcile order failed', [
                    'merchant_order' => (string)$merchantOrder,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        return count($orders);
    }

    public function publicOrder(array|object $order): array
    {
        $row = (array)$order;
        return [
            'merchant_order' => (string)$row['merchant_order'],
            'provider' => (string)$row['provider'],
            'provider_order_number' => (string)($row['provider_order_number'] ?? ''),
            'package_code' => (string)$row['package_code'],
            'points' => (string)$row['points'],
            'currency' => (string)$row['currency'],
            'total_fee' => (string)$row['total_fee'],
            'status' => (string)$row['status'],
            'pay_url' => (string)($row['pay_url'] ?? ''),
            'wallet_type' => (string)($row['wallet_type'] ?? ''),
            'bank_code' => (string)($row['bank_code'] ?? ''),
            'product_code' => (string)($row['product_code'] ?? ''),
            'created_at' => (string)$row['created_at'],
            'credited_at' => (string)($row['credited_at'] ?? ''),
            'last_error' => (string)($row['last_error_message'] ?? ''),
        ];
    }

    private function storeCreationResult(string $merchantOrder, array $result): void
    {
        $status = (string)$result['status'];
        if ($status === 'success') {
            $this->creditSuccess($merchantOrder, $result, false);
            return;
        }
        $now = date('Y-m-d H:i:s');
        Db::table('ga_payment_orders')->where('merchant_order', $merchantOrder)->update([
            'provider_order_number' => trim((string)$result['provider_order_number']),
            'pay_url' => trim((string)($result['pay_url'] ?? '')),
            'status' => $status,
            'next_query_at' => in_array($status, ['pending', 'unknown'], true) ? date('Y-m-d H:i:s', time() + 60) : null,
            'last_error_code' => '',
            'last_error_message' => '',
            'updated_at' => $now,
        ]);
    }

    private function applyResult(string $merchantOrder, array $result, bool $fromCallback): void
    {
        $status = (string)$result['status'];
        if ($status === 'success') {
            $this->creditSuccess($merchantOrder, $result, $fromCallback);
            return;
        }
        if (!in_array($status, ['pending', 'unknown', 'fail'], true)) {
            throw new RuntimeException('规范化支付状态无效：' . $status);
        }
        $this->updateNonSuccessStatus($merchantOrder, $status, $result, $fromCallback);
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
            $providerLabel = $this->providers->get((string)$order->provider)->label();
            Db::table('ga_user_point_transactions')->insert([
                'user_id' => (int)$order->user_id,
                'type' => 'recharge',
                'amount' => (string)$order->points,
                'balance_after' => $balanceAfter,
                'description' => $providerLabel . ' 充值 ' . (string)$order->merchant_order,
                'related_user_id' => null,
                'related_role_id' => '',
                'related_payment_order_id' => (int)$order->id,
                'ip_address' => '',
                'created_at' => $now,
            ]);
            Db::table('ga_payment_orders')->where('id', (int)$order->id)->update([
                'status' => 'success',
                'provider_order_number' => trim((string)($providerData['provider_order_number'] ?? $order->provider_order_number)),
                'pay_url' => trim((string)($providerData['pay_url'] ?? $order->pay_url)),
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
        $attempts = (int)$order['query_attempts'] + ($fromCallback ? 0 : 1);
        $updates = [
            'status' => $status,
            'provider_order_number' => trim((string)($data['provider_order_number'] ?? $order['provider_order_number'] ?? '')),
            'pay_url' => trim((string)($data['pay_url'] ?? $order['pay_url'] ?? '')),
            'utr' => trim((string)($data['utr'] ?? $order['utr'] ?? '')),
            'query_attempts' => $attempts,
            'updated_at' => $now,
        ];
        if ($fromCallback) {
            $updates['notified_at'] = $now;
        } else {
            $updates['last_queried_at'] = $now;
        }
        $updates['next_query_at'] = in_array($status, ['pending', 'unknown'], true)
            ? $this->nextQueryAt($attempts)
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

    private function assertResultMatchesOrder(array $result, array $order): void
    {
        if (trim((string)($result['merchant_order'] ?? '')) !== (string)$order['merchant_order']) {
            throw new PaymentProviderException('支付平台响应商户订单号不一致', false);
        }
        if (PaymentAmount::normalize($result['total_fee'] ?? '', '支付平台') !== PaymentAmount::normalize($order['total_fee'], '本地订单')) {
            throw new PaymentProviderException('支付平台响应金额不一致', false);
        }
        $remoteOrder = trim((string)($result['provider_order_number'] ?? ''));
        if ($remoteOrder === '') {
            throw new PaymentProviderException('支付平台响应缺少平台订单号', false);
        }
        $existingRemoteOrder = trim((string)($order['provider_order_number'] ?? ''));
        if ($existingRemoteOrder !== '' && !hash_equals($existingRemoteOrder, $remoteOrder)) {
            throw new PaymentProviderException('支付平台订单号不一致', false);
        }
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

    private function optionalText(array $input, string $key, int $maxLength, string $label): string
    {
        $value = trim((string)($input[$key] ?? ''));
        if ($value !== '' && (mb_strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/u', $value))) {
            throw new InvalidArgumentException("{$label}格式无效");
        }
        return $value;
    }

    private function payerSnapshot(array $input, bool $required): array
    {
        if ($required) {
            $name = $this->requiredText($input, 'customer_name', 128, '付款人姓名');
            $mobile = $this->requiredText($input, 'customer_mobile', 64, '付款人手机号');
            $bankAccount = trim((string)($input['bank_account'] ?? ''));
            if ($bankAccount === '') {
                throw new InvalidArgumentException('付款帐号不能为空');
            }
            return [$name, $mobile, $bankAccount];
        }

        return [
            $this->optionalText($input, 'customer_name', 128, '付款人姓名'),
            $this->optionalText($input, 'customer_mobile', 64, '付款人手机号'),
            trim((string)($input['bank_account'] ?? '')),
        ];
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
