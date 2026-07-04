<?php

namespace app\service;

use app\exception\ApiException;
use app\support\I18n;
use support\Request;

class ThirdPartyGateway
{
    private const SIGNATURE_TTL_SECONDS = 300;
    public const TRANSPORT_WEBSOCKET = 'websocket';
    public const TRANSPORT_HTTP = 'http';

    public function __construct(
        private array $config,
        private string $locale = I18n::DEFAULT_LOCALE
    )
    {
        $this->locale = I18n::normalizeLocale($this->locale);
    }

    public function applyConfig(int $userId, array $payload): never
    {
        if (empty($this->config['enabled'])) {
            throw new ApiException(I18n::t('api.third_party.disabled', [], $this->locale), 409);
        }

        throw new ApiException(I18n::t('api.third_party.not_implemented', [], $this->locale), 501);
    }

    public function startAccount(array $account, array $config, string $gamePassword): array
    {
        $this->assertEnabled();
        $transport = $this->transport();

        if ($transport === self::TRANSPORT_WEBSOCKET) {
            if (trim((string)($this->config['ws_url'] ?? '')) === '') {
                throw new ApiException(I18n::t('api.third_party.websocket_unconfigured', [], $this->locale), 503);
            }
            throw new ApiException(I18n::t('api.third_party.websocket_protocol_pending', [], $this->locale), 501);
        }

        if (trim((string)($this->config['base_url'] ?? '')) === '') {
            throw new ApiException(I18n::t('api.third_party.http_unconfigured', [], $this->locale), 503);
        }
        throw new ApiException(I18n::t('api.third_party.http_protocol_pending', [], $this->locale), 501);
    }

    public function stopAccount(array $account): array
    {
        $this->assertEnabled();
        $transport = $this->transport();

        if ($transport === self::TRANSPORT_WEBSOCKET) {
            if (trim((string)($this->config['ws_url'] ?? '')) === '') {
                throw new ApiException(I18n::t('api.third_party.websocket_unconfigured', [], $this->locale), 503);
            }
            throw new ApiException(I18n::t('api.third_party.websocket_protocol_pending', [], $this->locale), 501);
        }

        if (trim((string)($this->config['base_url'] ?? '')) === '') {
            throw new ApiException(I18n::t('api.third_party.http_unconfigured', [], $this->locale), 503);
        }
        throw new ApiException(I18n::t('api.third_party.http_protocol_pending', [], $this->locale), 501);
    }

    public function currentRuntimeData(array $account): array
    {
        if (($account['status'] ?? '') !== 'running') {
            return [];
        }

        if (empty($this->config['enabled'])) {
            return [];
        }

        return [];
    }

    public function verifyInboundRequest(Request $request): void
    {
        $this->verifyInboundSignature(
            $request->method(),
            $request->path(),
            (string)$request->header('x-timestamp', ''),
            (string)$request->header('x-signature', '')
        );
    }

    public function verifyInboundSignature(
        string $method,
        string $path,
        string $timestamp,
        string $signature,
        ?int $now = null
    ): void
    {
        if (empty($this->config['enabled'])) {
            throw new ApiException(I18n::t('api.third_party.disabled', [], $this->locale), 409);
        }

        $secret = (string)($this->config['sign_secret'] ?? '');
        if ($secret === '') {
            throw new ApiException(I18n::t('api.third_party.sign_secret_missing', [], $this->locale), 503);
        }

        $timestamp = trim($timestamp);
        $signature = trim($signature);
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
            throw new ApiException(I18n::t('api.third_party.signature_required', [], $this->locale), 401);
        }

        $now ??= time();
        if (abs($now - (int)$timestamp) > self::SIGNATURE_TTL_SECONDS) {
            throw new ApiException(I18n::t('api.third_party.signature_expired', [], $this->locale), 401);
        }

        $expected = hash_hmac('sha256', $this->signaturePayload($method, $path, $timestamp), $secret);
        if (!hash_equals($expected, $signature)) {
            throw new ApiException(I18n::t('api.third_party.signature_invalid', [], $this->locale), 401);
        }
    }

    private function signaturePayload(string $method, string $path, string $timestamp): string
    {
        $normalizedPath = '/' . ltrim($path, '/');
        return strtoupper($method) . "\n" . $normalizedPath . "\n" . $timestamp;
    }

    private function assertEnabled(): void
    {
        if (empty($this->config['enabled'])) {
            throw new ApiException(I18n::t('api.third_party.disabled', [], $this->locale), 409);
        }
    }

    private function transport(): string
    {
        $transport = strtolower(trim((string)($this->config['transport'] ?? self::TRANSPORT_WEBSOCKET)));
        if (!in_array($transport, [self::TRANSPORT_WEBSOCKET, self::TRANSPORT_HTTP], true)) {
            throw new ApiException(I18n::t('api.third_party.transport_invalid', [], $this->locale), 503);
        }
        return $transport;
    }
}
