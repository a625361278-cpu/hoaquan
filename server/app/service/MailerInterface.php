<?php

namespace app\service;

interface MailerInterface
{
    public function send(string $toEmail, string $subject, string $body): void;
}
