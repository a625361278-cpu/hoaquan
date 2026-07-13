#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "用法：php scripts/generate_ronnypay_keypair.php <仓库外输出目录> [2048|3072|4096]" . PHP_EOL);
    exit(2);
}

$outputDirectory = rtrim((string)$argv[1], "\\/");
$bits = isset($argv[2]) ? (int)$argv[2] : 2048;
if (!in_array($bits, [2048, 3072, 4096], true)) {
    fwrite(STDERR, 'RSA 位数只允许 2048、3072 或 4096' . PHP_EOL);
    exit(2);
}
if ($outputDirectory === '' || !is_dir($outputDirectory)) {
    fwrite(STDERR, '输出目录不存在，请先在仓库外创建目录' . PHP_EOL);
    exit(2);
}

$privatePath = $outputDirectory . DIRECTORY_SEPARATOR . 'private_key.pem';
$publicPath = $outputDirectory . DIRECTORY_SEPARATOR . 'public_key.pem';
if (file_exists($privatePath) || file_exists($publicPath)) {
    fwrite(STDERR, '目标密钥文件已存在，拒绝覆盖' . PHP_EOL);
    exit(1);
}

$options = [
    'private_key_bits' => $bits,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];
$windowsConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
if (is_file($windowsConfig)) {
    $options['config'] = $windowsConfig;
}

$key = openssl_pkey_new($options);
if ($key === false) {
    fwrite(STDERR, 'RSA 密钥生成失败：' . (openssl_error_string() ?: 'unknown error') . PHP_EOL);
    exit(1);
}
if (!openssl_pkey_export($key, $privatePem, null, $options)) {
    fwrite(STDERR, '私钥导出失败：' . (openssl_error_string() ?: 'unknown error') . PHP_EOL);
    exit(1);
}
$details = openssl_pkey_get_details($key);
if (!is_array($details) || empty($details['key'])) {
    fwrite(STDERR, '公钥导出失败' . PHP_EOL);
    exit(1);
}

$privateHandle = @fopen($privatePath, 'x');
if ($privateHandle === false) {
    fwrite(STDERR, '无法以独占方式创建私钥文件' . PHP_EOL);
    exit(1);
}
try {
    if (fwrite($privateHandle, $privatePem) !== strlen($privatePem)) {
        throw new RuntimeException('私钥文件写入不完整');
    }
} finally {
    fclose($privateHandle);
}
@chmod($privatePath, 0600);

$publicHandle = @fopen($publicPath, 'x');
if ($publicHandle === false) {
    @unlink($privatePath);
    fwrite(STDERR, '无法以独占方式创建公钥文件' . PHP_EOL);
    exit(1);
}
try {
    $publicPem = (string)$details['key'];
    if (fwrite($publicHandle, $publicPem) !== strlen($publicPem)) {
        throw new RuntimeException('公钥文件写入不完整');
    }
} catch (Throwable $e) {
    fclose($publicHandle);
    @unlink($publicPath);
    @unlink($privatePath);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
fclose($publicHandle);
@chmod($publicPath, 0644);

echo "RonnyPay RSA-{$bits} 密钥对已生成" . PHP_EOL;
echo "私钥：{$privatePath}" . PHP_EOL;
echo "公钥：{$publicPath}" . PHP_EOL;
echo '请先让 RonnyPay 确认 RSA 位数和 PEM 格式，再提交公钥；私钥不得发送或入库。' . PHP_EOL;
