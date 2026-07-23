<?php

namespace tests\Feature;

use app\support\I18n;
use PHPUnit\Framework\TestCase;
use support\Request;

class I18nServiceTest extends TestCase
{
    public function testTranslationPackagesHaveTheSameNonEmptyKeys(): void
    {
        $basePath = dirname(__DIR__, 2) . '/resource/translations';
        $zh = $this->readMessages($basePath . '/zh_CN/messages.json');
        $vi = $this->readMessages($basePath . '/vi/messages.json');

        $this->assertSame(array_keys($zh), array_keys($vi));
        $this->assertNotEmpty($zh);

        foreach ($zh as $key => $value) {
            $this->assertNotSame('', trim((string)$value), "zh_CN translation is empty: {$key}");
            $this->assertNotSame('', trim((string)$vi[$key]), "vi translation is empty: {$key}");
        }
    }

    public function testVietnameseTranslationCanBeResolvedByKey(): void
    {
        $this->assertSame('Đăng nhập', I18n::t('auth.login', [], 'vi'));
    }

    public function testAdminLocaleIgnoresUserClientLocaleCookieByDefault(): void
    {
        $request = new Request("GET /app/admin HTTP/1.1\r\nHost: example.test\r\nCookie: gameassist_locale=vi\r\n\r\n");

        $this->assertSame('zh_CN', I18n::adminLocaleFromRequest($request));
        $this->assertSame('vi', I18n::localeFromRequest($request));
    }

    public function testAdminLocaleUsesDedicatedAdminLocaleCookie(): void
    {
        $request = new Request("GET /app/admin HTTP/1.1\r\nHost: example.test\r\nCookie: gameassist_locale=vi; gameassist_admin_locale=vi\r\n\r\n");

        $this->assertSame('vi', I18n::adminLocaleFromRequest($request));
    }

    private function readMessages(string $path): array
    {
        $this->assertFileExists($path);
        $messages = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($messages);
        ksort($messages);
        return $messages;
    }
}
