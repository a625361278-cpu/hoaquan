<?php

namespace app\service;

use app\exception\ApiException;
use app\support\I18n;

class CredentialCipher
{
    private const METHOD = 'aes-256-gcm';

    public function __construct(
        private string $key,
        private string $locale = I18n::DEFAULT_LOCALE
    )
    {
        $this->key = trim($this->key);
        $this->locale = I18n::normalizeLocale($this->locale);
    }

    public function encrypt(string $plainText): string
    {
        $this->assertConfigured();

        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            self::METHOD,
            $this->normalizedKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($cipherText === false || $tag === '') {
            throw new ApiException(I18n::t('api.game.password_encrypt_failed', [], $this->locale), 500);
        }

        return 'v1:' . base64_encode($iv . $tag . $cipherText);
    }

    public function decrypt(string $encrypted): string
    {
        $this->assertConfigured();

        if (!str_starts_with($encrypted, 'v1:')) {
            throw new ApiException(I18n::t('api.game.password_cipher_invalid', [], $this->locale), 500);
        }

        $raw = base64_decode(substr($encrypted, 3), true);
        if ($raw === false || strlen($raw) <= 28) {
            throw new ApiException(I18n::t('api.game.password_cipher_invalid', [], $this->locale), 500);
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipherText = substr($raw, 28);
        $plainText = openssl_decrypt(
            $cipherText,
            self::METHOD,
            $this->normalizedKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plainText === false) {
            throw new ApiException(I18n::t('api.game.password_cipher_invalid', [], $this->locale), 500);
        }

        return $plainText;
    }

    private function assertConfigured(): void
    {
        if ($this->key === '') {
            throw new ApiException(I18n::t('api.game.password_key_missing', [], $this->locale), 503);
        }
    }

    private function normalizedKey(): string
    {
        return hash('sha256', $this->key, true);
    }
}
