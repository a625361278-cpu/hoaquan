<?php

namespace tests\Feature;

use app\service\RonnyPaySigner;
use PHPUnit\Framework\TestCase;

final class RonnyPaySignerTest extends TestCase
{
    public function testCanonicalRequestExcludesEmptyValuesAndKeepsTrailingAmpersand(): void
    {
        $signer = new RonnyPaySigner();
        $text = $signer->requestSigningText([
            'timestamp' => '1720000000',
            'sign' => 'ignored',
            'bank_code' => '',
            'merchant_order' => 'GA001',
            'merchant_id' => 'M001',
            'nullable' => null,
        ]);

        $this->assertSame('merchant_id=M001&merchant_order=GA001&timestamp=1720000000&', $text);
    }

    public function testRsaSha256SignatureUsesUnpaddedBase64UrlAndVerifies(): void
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $windowsConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (is_file($windowsConfig)) {
            $options['config'] = $windowsConfig;
        }
        $key = openssl_pkey_new($options);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privatePem, null, $options));
        $publicPem = openssl_pkey_get_details($key)['key'];
        $path = tempnam(sys_get_temp_dir(), 'ronnypay-key-');
        $this->assertNotFalse(file_put_contents($path, $privatePem));

        try {
            $signer = new RonnyPaySigner();
            $parameters = ['merchant_id' => 'M001', 'merchant_order' => 'GA001', 'total_fee' => '149000.00'];
            $signature = $signer->signRequest($parameters, $path);

            $this->assertDoesNotMatchRegularExpression('/[+=\/]/', $signature);
            $this->assertTrue($signer->verifyRequestSignature($parameters, $signature, $publicPem));
        } finally {
            @unlink($path);
        }
    }

    public function testCallbackMd5UsesSortedParametersAndSecret(): void
    {
        $signer = new RonnyPaySigner();
        $parameters = ['status' => 'success', 'merchant_order' => 'GA001', 'sign' => 'ignored', 'utr' => ''];

        $this->assertSame('merchant_order=GA001&status=success&key=callback-secret', $signer->callbackSigningText($parameters, 'callback-secret'));
        $signature = md5('merchant_order=GA001&status=success&key=callback-secret');
        $parameters['sign'] = $signature;
        $this->assertTrue($signer->verifyCallback($parameters, 'callback-secret'));
    }
}
