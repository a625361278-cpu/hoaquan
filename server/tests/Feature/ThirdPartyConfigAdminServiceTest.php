<?php

namespace tests\Feature;

use app\service\SystemSettingService;
use PHPUnit\Framework\TestCase;
use plugin\admin\app\service\ThirdPartyConfigAdminService;
use RuntimeException;

class ThirdPartyConfigAdminServiceTest extends TestCase
{
    public function testSaveAcceptsEmptySignSecretAndNormalizesWebSocketUrls(): void
    {
        $settings = new class extends SystemSettingService {
            public array $saved = [];

            public function thirdPartyRawSettings(): array
            {
                return [];
            }

            public function saveSettings(array $settings): void
            {
                $this->saved = $settings;
            }
        };

        $service = new ThirdPartyConfigAdminService($settings);

        $service->save([
            'third_party_enabled' => '1',
            'third_party_ws_urls' => " ws://third-party/a \r\n\r\n wss://third-party/b ",
            'third_party_ws_connection_capacity' => '10',
            'third_party_sign_secret' => '',
        ]);

        $this->assertSame('1', $settings->saved['third_party_enabled']);
        $this->assertSame("ws://third-party/a\nwss://third-party/b", $settings->saved['third_party_ws_urls']);
        $this->assertSame('ws://third-party/a', $settings->saved['third_party_ws_url']);
        $this->assertSame('10', $settings->saved['third_party_ws_connection_capacity']);
        $this->assertSame('', $settings->saved['third_party_sign_secret']);
        $this->assertSame('websocket', $settings->saved['third_party_transport']);
    }

    public function testSaveRejectsEnabledConfigWithoutWebSocketUrls(): void
    {
        $service = new ThirdPartyConfigAdminService(new class extends SystemSettingService {
            public function saveSettings(array $settings): void
            {
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('第三方WebSocket地址未配置');

        $service->save([
            'third_party_enabled' => '1',
            'third_party_ws_urls' => '',
            'third_party_ws_connection_capacity' => '10',
            'third_party_sign_secret' => '',
        ]);
    }

    public function testConfigReturnsEditableValues(): void
    {
        $service = new ThirdPartyConfigAdminService(new class extends SystemSettingService {
            public function thirdPartyRawSettings(): array
            {
                return [
                    'third_party_enabled' => '1',
                    'third_party_ws_urls' => "ws://third-party/a\nws://third-party/b",
                    'third_party_ws_connection_capacity' => '8',
                    'third_party_sign_secret' => '',
                ];
            }
        });

        $config = $service->config();

        $this->assertTrue($config['enabled']);
        $this->assertSame("ws://third-party/a\nws://third-party/b", $config['ws_urls_text']);
        $this->assertSame(8, $config['ws_connection_capacity']);
        $this->assertSame('', $config['sign_secret']);
    }
}
