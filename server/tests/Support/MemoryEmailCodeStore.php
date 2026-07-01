<?php

namespace tests\Support;

use app\exception\ApiException;
use app\service\EmailCodeStoreInterface;

class MemoryEmailCodeStore implements EmailCodeStoreInterface
{
    private array $codes = [];
    private bool $cooldown = false;

    public function assertCanSend(string $email, string $purpose = 'register'): void
    {
        if ($this->cooldown) {
            throw new ApiException('验证码发送太频繁，请60秒后再试', 429);
        }
    }

    public function store(string $email, string $code, string $purpose = 'register'): void
    {
        $this->codes[$this->key($email, $purpose)] = $code;
    }

    public function verify(string $email, string $code, string $purpose = 'register'): void
    {
        $key = $this->key($email, $purpose);
        if (!isset($this->codes[$key])) {
            throw new ApiException('邮箱验证码已过期或未发送', 422);
        }
        if ($this->codes[$key] !== $code) {
            throw new ApiException('邮箱验证码错误', 422);
        }
        unset($this->codes[$key]);
    }

    public function forceCode(string $email, string $code, string $purpose = 'register'): void
    {
        $this->codes[$this->key($email, $purpose)] = $code;
    }

    public function enableCooldown(): void
    {
        $this->cooldown = true;
    }

    private function key(string $email, string $purpose): string
    {
        return $purpose . ':' . $email;
    }
}
