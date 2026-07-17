<?php

namespace app\service;

use app\exception\PaymentProviderException;
use InvalidArgumentException;

final class RonnyPayProvider implements PaymentProviderInterface
{
    private RonnyPayConfig $config;
    private RonnyPayGatewayInterface $gateway;
    private RonnyPaySigner $signer;

    public function __construct(
        ?RonnyPayConfig $config = null,
        ?RonnyPayGatewayInterface $gateway = null,
        ?RonnyPaySigner $signer = null
    ) {
        $this->config = $config ?? new RonnyPayConfig();
        $this->signer = $signer ?? new RonnyPaySigner();
        $this->gateway = $gateway ?? new GuzzleRonnyPayGateway($this->config, $this->signer);
    }

    public function code(): string { return 'ronnypay'; }
    public function label(): string { return 'MoMoPay'; }
    public function assertCanCreateOrder(): void { $this->config->assertCanCreateOrder(); }
    public function apiConfigured(): bool { return $this->config->apiConfigured(); }

    public function orderMetadata(): array
    {
        return [
            'product_code' => '',
            'wallet_type' => $this->config->walletType(),
            'bank_code' => $this->config->bankCode(),
        ];
    }

    public function createOrder(array $order): array
    {
        $data = $this->gateway->createOrder([
            'merchant_order' => $order['merchant_order'],
            'total_fee' => $order['provider_amount'],
            'customer_name' => $order['customer_name'],
            'customer_mobile' => $order['customer_mobile'],
            'bank_account' => $order['bank_account'],
        ]);
        return $this->normalize($data, $order, true);
    }

    public function queryOrder(array $order): array
    {
        return $this->normalize($this->gateway->queryOrder((string)$order['merchant_order']), $order, false);
    }

    public function parseCallback(array $parameters): array
    {
        $this->config->assertCallbackConfigured();
        if (!$this->signer->verifyCallback($parameters, $this->config->callbackSecret())) {
            throw new InvalidArgumentException('RonnyPay 回调签名无效');
        }
        return $this->normalize($parameters, [
            'merchant_order' => trim((string)($parameters['merchant_order'] ?? '')),
            'total_fee' => (string)($parameters['total_fee'] ?? ''),
            'provider_order_number' => '',
        ], false);
    }

    private function normalize(array $data, array $order, bool $creation): array
    {
        $merchantOrder = trim((string)($order['merchant_order'] ?? ''));
        if (trim((string)($data['merchant_id'] ?? '')) !== $this->config->merchantId()) {
            throw new PaymentProviderException('RonnyPay 响应商户号不一致', false);
        }
        if (trim((string)($data['merchant_order'] ?? '')) !== $merchantOrder) {
            throw new PaymentProviderException('RonnyPay 响应商户订单号不一致', false);
        }
        if (PaymentAmount::normalize($data['total_fee'] ?? '', 'RonnyPay') !== PaymentAmount::normalize($order['total_fee'] ?? '', 'RonnyPay')) {
            throw new PaymentProviderException('RonnyPay 响应金额不一致', false);
        }
        $remoteOrder = trim((string)($data['order_number'] ?? ''));
        if ($remoteOrder === '') {
            throw new PaymentProviderException('RonnyPay 响应缺少平台订单号', false);
        }
        $existingRemoteOrder = trim((string)($order['provider_order_number'] ?? ''));
        if ($existingRemoteOrder !== '' && !hash_equals($existingRemoteOrder, $remoteOrder)) {
            throw new PaymentProviderException('RonnyPay 平台订单号不一致', false);
        }
        $status = strtolower(trim((string)($data['status'] ?? '')));
        if (!in_array($status, ['pending', 'success', 'fail'], true)) {
            throw new PaymentProviderException('RonnyPay 状态无效', false);
        }
        $payUrl = trim((string)($data['pay_url'] ?? ''));
        if ($creation && $status === 'pending' && !$this->isHttpsUrl($payUrl)) {
            throw new PaymentProviderException('RonnyPay 下单响应支付链接无效', false);
        }
        return [
            'merchant_order' => $merchantOrder,
            'provider_order_number' => $remoteOrder,
            'status' => $status,
            'total_fee' => PaymentAmount::normalize($data['total_fee'], 'RonnyPay'),
            'pay_url' => $payUrl,
            'utr' => trim((string)($data['utr'] ?? '')),
        ];
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
