<?php

namespace app\service;

use app\exception\PaymentProviderException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use support\Log;

final class GuzzleMkPayGateway implements MkPayGatewayInterface
{
    private ClientInterface $client;

    public function __construct(
        private MkPayConfig $config,
        private ?MkPaySigner $signer = null,
        ?ClientInterface $client = null
    ) {
        $this->signer ??= new MkPaySigner();
        $this->client = $client ?? new Client([
            'base_uri' => $this->config->baseUrl(),
            'verify' => true,
            'connect_timeout' => 5.0,
            'timeout' => 12.0,
            'http_errors' => false,
        ]);
    }

    public function createOrder(array $order): array
    {
        $this->config->assertCanCreateOrder();
        return $this->send('/api/v1/pay', [
            'mch_id' => $this->config->merchantId(),
            'amount' => (int)$order['amount'],
            'merchant_order_id' => (string)$order['merchant_order'],
            'product_code' => $this->config->productCode(),
            'notify_url' => $this->config->notifyUrl(),
        ], 201);
    }

    public function queryOrder(string $merchantOrder): array
    {
        $this->config->assertApiConfigured();
        return $this->send('/api/v1/query-by-moid', [
            'mch_id' => $this->config->merchantId(),
            'merchant_order_id' => $merchantOrder,
        ], null);
    }

    private function send(string $path, array $parameters, ?int $expectedStatus): array
    {
        try {
            $body = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PaymentProviderException('MkPay 请求 JSON 编码失败', false, '', 0, $e);
        }
        $timestamp = (string)time();
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Mch-ID' => $this->config->merchantId(),
            'X-Timestamp' => $timestamp,
            'X-Signature' => $this->signer->requestSignature($timestamp, $body, $this->config->merchantSecret()),
            'X-Nonce' => bin2hex(random_bytes(16)),
        ];
        try {
            $response = $this->client->request('POST', $path, ['body' => $body, 'headers' => $headers]);
        } catch (ConnectException $e) {
            throw new PaymentProviderException('MkPay 网络连接或请求超时', true, '', 0, $e);
        } catch (RequestException $e) {
            throw new PaymentProviderException('MkPay 请求失败', true, '', 0, $e);
        } catch (GuzzleException $e) {
            throw new PaymentProviderException('MkPay HTTP 客户端异常', true, '', 0, $e);
        }

        $httpStatus = $response->getStatusCode();
        $responseBody = (string)$response->getBody();
        try {
            $payload = json_decode($responseBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logRejectedResponse($path, $httpStatus, $responseBody);
            throw new PaymentProviderException('MkPay 返回了无效 JSON', $httpStatus >= 500, '', $httpStatus, $e);
        }
        if (!is_array($payload)) {
            throw new PaymentProviderException('MkPay 返回结构无效', $httpStatus >= 500, '', $httpStatus);
        }
        $accepted = $expectedStatus === null
            ? $httpStatus >= 200 && $httpStatus < 300
            : $httpStatus === $expectedStatus;
        if (!$accepted) {
            $this->logRejectedResponse($path, $httpStatus, $responseBody);
            $message = trim((string)($payload['error'] ?? $payload['message'] ?? 'MkPay 拒绝请求'));
            throw new PaymentProviderException(
                $message === '' ? 'MkPay 拒绝请求' : $message,
                $httpStatus >= 500,
                (string)($payload['error'] ?? ''),
                $httpStatus
            );
        }
        return $payload;
    }

    private function logRejectedResponse(string $path, int $httpStatus, string $body): void
    {
        $summary = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? '';
        $summary = preg_replace('/("?(?:sign|signature|secret|token)"?\s*:\s*)"(?:\\\\.|[^"\\\\])*"/iu', '$1"***"', $summary) ?? '';
        $summary = preg_replace_callback('/\d{7,}/', static fn(array $m): string => substr($m[0], 0, 3) . '***' . substr($m[0], -3), $summary) ?? '';
        Log::warning('MkPay response rejected', [
            'path' => $path,
            'http_status' => $httpStatus,
            'body_bytes' => strlen($body),
            'body_sha256' => hash('sha256', $body),
            'body_summary' => mb_substr($summary, 0, 500),
        ]);
    }
}
