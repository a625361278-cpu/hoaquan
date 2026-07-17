<?php

namespace app\service;

use app\exception\PaymentProviderException;
use InvalidArgumentException;

final class MkPayProvider implements PaymentProviderInterface
{
    private MkPayConfig $config;
    private MkPayGatewayInterface $gateway;
    private MkPaySigner $signer;

    public function __construct(
        ?MkPayConfig $config = null,
        ?MkPayGatewayInterface $gateway = null,
        ?MkPaySigner $signer = null
    ) {
        $this->config = $config ?? new MkPayConfig();
        $this->signer = $signer ?? new MkPaySigner();
        $this->gateway = $gateway ?? new GuzzleMkPayGateway($this->config, $this->signer);
    }

    public function code(): string { return 'mkpay'; }
    public function label(): string { return 'MkPay'; }
    public function assertCanCreateOrder(): void { $this->config->assertCanCreateOrder(); }
    public function apiConfigured(): bool { return $this->config->apiConfigured(); }

    public function orderMetadata(): array
    {
        return ['product_code' => $this->config->productCode(), 'wallet_type' => '', 'bank_code' => ''];
    }

    public function createOrder(array $order): array
    {
        return $this->normalize($this->gateway->createOrder([
            'merchant_order' => $order['merchant_order'],
            'amount' => $order['provider_amount'],
        ]), $order, true);
    }

    public function queryOrder(array $order): array
    {
        return $this->normalize($this->gateway->queryOrder((string)$order['merchant_order']), $order, false);
    }

    public function parseCallback(array $parameters): array
    {
        $this->config->assertApiConfigured();
        if (!$this->signer->verifyCallback($parameters, $this->config->merchantSecret())) {
            throw new InvalidArgumentException('MkPay 回调签名无效');
        }
        return $this->normalize($parameters, [
            'merchant_order' => trim((string)($parameters['merchant_order_id'] ?? '')),
            'total_fee' => (string)($parameters['amount'] ?? ''),
            'provider_order_number' => '',
        ], false);
    }

    private function normalize(array $data, array $order, bool $creation): array
    {
        $merchantOrder = trim((string)($order['merchant_order'] ?? ''));
        if (trim((string)($data['merchant_order_id'] ?? '')) !== $merchantOrder) {
            throw new PaymentProviderException('MkPay 响应商户订单号不一致', false);
        }
        if (trim((string)($data['product_type'] ?? '')) !== 'PAY') {
            throw new PaymentProviderException('MkPay 响应业务类型不是 PAY', false);
        }
        if (trim((string)($data['currency'] ?? '')) !== PaymentOrderService::CURRENCY) {
            throw new PaymentProviderException('MkPay 响应币种不一致', false);
        }
        if (PaymentAmount::normalize($data['amount'] ?? '', 'MkPay') !== PaymentAmount::normalize($order['total_fee'] ?? '', 'MkPay')) {
            throw new PaymentProviderException('MkPay 响应金额不一致', false);
        }
        $remoteOrder = trim((string)($data['pay_order_id'] ?? ''));
        if ($remoteOrder === '') {
            throw new PaymentProviderException('MkPay 响应缺少平台订单号', false);
        }
        $existingRemoteOrder = trim((string)($order['provider_order_number'] ?? ''));
        if ($existingRemoteOrder !== '' && !hash_equals($existingRemoteOrder, $remoteOrder)) {
            throw new PaymentProviderException('MkPay 平台订单号不一致', false);
        }
        $rawStatus = trim((string)($data['status_code'] ?? ''));
        if (!preg_match('/^\d+$/', $rawStatus)) {
            throw new PaymentProviderException('MkPay 状态码无效', false);
        }
        $status = match ((int)$rawStatus) {
            0, 1 => 'pending',
            2 => 'success',
            3, 4, 6 => 'fail',
            7 => 'unknown',
            10, 11, 15, 20, 30 => throw new PaymentProviderException('MkPay 返回了代付专用状态码', false, $rawStatus),
            default => throw new PaymentProviderException('MkPay 返回未知状态码', false, $rawStatus),
        };
        $payUrl = trim((string)($data['redirect_url'] ?? ''));
        if ($payUrl === '') {
            $payUrl = trim((string)($data['qr_code_url'] ?? ''));
        }
        if ($creation && $status === 'pending' && !$this->isHttpsUrl($payUrl)) {
            throw new PaymentProviderException('MkPay 下单响应缺少有效支付地址', false);
        }
        return [
            'merchant_order' => $merchantOrder,
            'provider_order_number' => $remoteOrder,
            'status' => $status,
            'total_fee' => PaymentAmount::normalize($data['amount'], 'MkPay'),
            'pay_url' => $payUrl,
            'utr' => '',
            'provider_status_code' => $rawStatus,
        ];
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
