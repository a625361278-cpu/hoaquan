<?php

namespace app\service;

use app\exception\ApiException;

class SmtpMailer implements MailerInterface
{
    private array $config;

    public function __construct(SystemSettingService $settings)
    {
        $this->config = $settings->smtpConfig();
    }

    public function send(string $toEmail, string $subject, string $body): void
    {
        $this->assertConfigured();

        $host = $this->config['host'];
        $port = $this->config['port'];
        $encryption = strtolower($this->config['encryption']);
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $socket = @stream_socket_client($remote, $errno, $error, 15, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            throw new ApiException("SMTP连接失败：{$error}", 500);
        }

        try {
            stream_set_timeout($socket, 15);
            $this->expect($socket, 220);
            $this->command($socket, 'EHLO gameassist.local', 250);

            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new ApiException('SMTP TLS握手失败', 500);
                }
                $this->command($socket, 'EHLO gameassist.local', 250);
            }

            $this->command($socket, 'AUTH LOGIN', 334);
            $this->command($socket, base64_encode($this->config['username']), 334);
            $this->command($socket, base64_encode($this->config['password']), 235);
            $this->command($socket, 'MAIL FROM:<' . $this->config['from_email'] . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command($socket, 'DATA', 354);

            $message = $this->buildMessage($toEmail, $subject, $body);
            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect($socket, 250);
            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    private function assertConfigured(): void
    {
        if (!$this->config['enabled']) {
            throw new ApiException('SMTP未启用，无法发送邮箱验证码', 503);
        }

        foreach (['host', 'port', 'username', 'password', 'from_email'] as $field) {
            if (empty($this->config[$field])) {
                throw new ApiException("SMTP配置缺失：{$field}", 503);
            }
        }

        if (!in_array(strtolower($this->config['encryption']), ['tls', 'ssl', 'none'], true)) {
            throw new ApiException('SMTP加密方式配置错误', 503);
        }
    }

    private function buildMessage(string $toEmail, string $subject, string $body): string
    {
        $fromName = $this->encodeHeader($this->config['from_name']);
        $subject = $this->encodeHeader($subject);
        $headers = [
            'From: ' . $fromName . ' <' . $this->config['from_email'] . '>',
            'To: <' . $toEmail . '>',
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function command($socket, string $command, int|array $expected): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->expect($socket, $expected);
    }

    private function expect($socket, int|array $expected): string
    {
        $expectedCodes = is_array($expected) ? $expected : [$expected];
        $response = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) {
                throw new ApiException('SMTP响应读取失败', 500);
            }
            $response .= $line;
            $code = (int)substr($line, 0, 3);
            $continued = isset($line[3]) && $line[3] === '-';
        } while ($continued);

        if (!in_array($code, $expectedCodes, true)) {
            throw new ApiException('SMTP返回异常：' . trim($response), 500);
        }

        return $response;
    }
}
