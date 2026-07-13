<?php

namespace app\service;

use app\exception\RonnyPayException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use support\Log;

final class GuzzleRonnyPayGateway implements RonnyPayGatewayInterface
{
    private ClientInterface $client;

    public function __construct(
        private RonnyPayConfig $config,
        private ?RonnyPaySigner $signer = null,
        ?ClientInterface $client = null
    ) {
        $this->signer ??= new RonnyPaySigner();
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
        $this->config->assertApiConfigured();
        $parameters = [
            'merchant_id' => $this->config->merchantId(),
            'merchant_order' => (string)$order['merchant_order'],
            'total_fee' => (string)$order['total_fee'],
            'notify_url' => $this->config->notifyUrl(),
            'timestamp' => (string)time(),
            'country' => 'VN',
            'customer_name' => (string)$order['customer_name'],
            'customer_mobile' => (string)$order['customer_mobile'],
            'bank_account' => (string)$order['bank_account'],
            'wallet_type' => $this->config->walletType(),
            'bank_code' => $this->config->bankCode(),
        ];
        return $this->send('/api/merchant/payin/order', $parameters);
    }

    public function queryOrder(string $merchantOrder): array
    {
        $this->config->assertApiConfigured();
        return $this->send('/api/merchant/payin/query', [
            'merchant_id' => $this->config->merchantId(),
            'merchant_order' => $merchantOrder,
            'timestamp' => (string)time(),
        ]);
    }

    private function send(string $path, array $parameters): array
    {
        $parameters = $this->signer->canonicalParameters($parameters);
        $parameters['sign'] = $this->signer->signRequest($parameters, $this->config->privateKeyPath());
        try {
            $response = $this->client->request('POST', $path, [
                'json' => $parameters,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (ConnectException $e) {
            throw new RonnyPayException('RonnyPay 网络连接或请求超时', true, '', 0, $e);
        } catch (RequestException $e) {
            throw new RonnyPayException('RonnyPay 请求失败', true, '', 0, $e);
        } catch (GuzzleException $e) {
            throw new RonnyPayException('RonnyPay HTTP 客户端异常', true, '', 0, $e);
        }

        $httpStatus = $response->getStatusCode();
        $responseBody = (string)$response->getBody();
        try {
            $payload = json_decode($responseBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logRejectedResponse($path, $httpStatus, $responseBody, (string)$response->getHeaderLine('Content-Type'));
            throw new RonnyPayException('RonnyPay 返回了无效 JSON', $httpStatus >= 500, '', $httpStatus, $e);
        }
        if (!is_array($payload)) {
            $this->logRejectedResponse($path, $httpStatus, $responseBody, (string)$response->getHeaderLine('Content-Type'));
            throw new RonnyPayException('RonnyPay 返回结构无效', $httpStatus >= 500, '', $httpStatus);
        }
        $code = (string)($payload['code'] ?? '');
        if ($httpStatus >= 500 || in_array($httpStatus, [502, 503], true)) {
            $this->logRejectedResponse($path, $httpStatus, $responseBody, (string)$response->getHeaderLine('Content-Type'));
            throw new RonnyPayException('RonnyPay 服务暂时不可用', true, $code, $httpStatus);
        }
        if ($httpStatus < 200 || $httpStatus >= 300 || $code !== '0') {
            $this->logRejectedResponse($path, $httpStatus, $responseBody, (string)$response->getHeaderLine('Content-Type'));
            $message = trim((string)($payload['message'] ?? $payload['msg'] ?? 'RonnyPay 拒绝请求'));
            throw new RonnyPayException($message, false, $code, $httpStatus);
        }
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            throw new RonnyPayException('RonnyPay 成功响应缺少 data', false, $code, $httpStatus);
        }
        return $data;
    }

    private function logRejectedResponse(string $path, int $httpStatus, string $body, string $contentType): void
    {
        $summary = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? '';
        $summary = preg_replace(
            '/("?(?:sign|bank_account|customer_mobile|pay_url|token|secret)"?\s*:\s*)(?:"(?:\\\\.|[^"\\\\])*"|[^,}\s]+)/iu',
            '$1***',
            $summary
        ) ?? '';
        $summary = preg_replace(
            '/((?:sign|bank_account|customer_mobile|pay_url|token|secret)\s*=)[^&\s]*/iu',
            '$1***',
            $summary
        ) ?? '';
        $summary = preg_replace_callback('/\d{7,}/', static function (array $matches): string {
            $value = $matches[0];
            return substr($value, 0, 3) . '***' . substr($value, -3);
        }, $summary) ?? '';

        Log::warning('RonnyPay response rejected', [
            'path' => $path,
            'http_status' => $httpStatus,
            'content_type' => $contentType,
            'body_bytes' => strlen($body),
            'body_sha256' => hash('sha256', $body),
            'body_summary' => mb_substr($summary, 0, 500),
        ]);
    }
}
