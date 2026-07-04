<?php

namespace app\service;

use app\exception\ApiException;
use app\support\I18n;
use support\Redis;

class RedisEmailCodeStore implements EmailCodeStoreInterface
{
    private const CODE_PREFIX = 'gameassist:email_code:';
    private const COOLDOWN_PREFIX = 'gameassist:email_code_cooldown:';
    private const CODE_TTL = 600;
    private const COOLDOWN_TTL = 60;
    private const PURPOSE_REGISTER = 'register';
    private const PURPOSE_PASSWORD_RESET = 'password_reset';

    public function __construct(private string $locale = I18n::DEFAULT_LOCALE)
    {
        $this->locale = I18n::normalizeLocale($this->locale);
    }

    public function assertCanSend(string $email, string $purpose = self::PURPOSE_REGISTER): void
    {
        $ttl = Redis::ttl($this->cooldownKey($email, $purpose));
        if ($ttl > 0) {
            throw new ApiException(I18n::t('api.auth.email_code_cooldown', ['seconds' => $ttl], $this->locale), 429);
        }
    }

    public function store(string $email, string $code, string $purpose = self::PURPOSE_REGISTER): void
    {
        Redis::setEx($this->codeKey($email, $purpose), self::CODE_TTL, password_hash($code, PASSWORD_DEFAULT));
        Redis::setEx($this->cooldownKey($email, $purpose), self::COOLDOWN_TTL, '1');
    }

    public function verify(string $email, string $code, string $purpose = self::PURPOSE_REGISTER): void
    {
        $key = $this->codeKey($email, $purpose);
        $hash = Redis::get($key);
        if (!$hash) {
            throw new ApiException(I18n::t('api.auth.email_code_expired', [], $this->locale), 422);
        }
        if (!password_verify($code, (string)$hash)) {
            throw new ApiException(I18n::t('api.auth.email_code_invalid', [], $this->locale), 422);
        }
        Redis::del($key);
    }

    private function codeKey(string $email, string $purpose): string
    {
        return self::CODE_PREFIX . $this->normalizePurpose($purpose) . ':' . $email;
    }

    private function cooldownKey(string $email, string $purpose): string
    {
        return self::COOLDOWN_PREFIX . $this->normalizePurpose($purpose) . ':' . $email;
    }

    private function normalizePurpose(string $purpose): string
    {
        if (!in_array($purpose, [self::PURPOSE_REGISTER, self::PURPOSE_PASSWORD_RESET], true)) {
            throw new \InvalidArgumentException('未知邮箱验证码用途');
        }
        return $purpose;
    }
}
