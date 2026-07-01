<?php

namespace tests\Support;

use app\exception\ApiException;
use app\service\MailerInterface;

class MemoryMailer implements MailerInterface
{
    public array $sent = [];

    public function __construct(private bool $enabled = true)
    {
    }

    public function send(string $toEmail, string $subject, string $body): void
    {
        if (!$this->enabled) {
            throw new ApiException('SMTP未启用，无法发送邮箱验证码', 503);
        }
        $this->sent[] = compact('toEmail', 'subject', 'body');
    }
}
