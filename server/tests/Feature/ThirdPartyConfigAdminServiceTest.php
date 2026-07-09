<?php

namespace tests\Feature;

use app\service\SystemSettingService;
use PHPUnit\Framework\TestCase;
use plugin\admin\app\service\ThirdPartyConfigAdminService;
use RuntimeException;

class ThirdPartyConfigAdminServiceTest extends TestCase
{
    public function testSaveAcceptsScriptEndpointTokenAndEmptySignSecret(): void
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
            'third_party_script_ws_url' => 'ws://example.com/ws/third-party/script',
            'third_party_script_token' => 'script-token',
            'third_party_sign_secret' => '',
        ]);

        $this->assertSame('1', $settings->saved['third_party_enabled']);
        $this->assertSame('ws://example.com/ws/third-party/script', $settings->saved['third_party_script_ws_url']);
        $this->assertSame('script-token', $settings->saved['third_party_script_token']);
        $this->assertSame('', $settings->saved['third_party_sign_secret']);
        $this->assertSame('websocket', $settings->saved['third_party_transport']);
    }

    public function testSaveRejectsEnabledConfigWithoutScriptToken(): void
    {
        $service = new ThirdPartyConfigAdminService(new class extends SystemSettingService {
            public function saveSettings(array $settings): void
            {
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('连接池Token不能为空');

        $service->save([
            'third_party_enabled' => '1',
            'third_party_script_ws_url' => 'ws://example.com/ws/third-party/script',
            'third_party_script_token' => '',
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
                    'third_party_script_ws_url' => 'ws://example.com/ws/third-party/script',
                    'third_party_script_token' => 'script-token',
                    'third_party_sign_secret' => '',
                ];
            }
        });

        $config = $service->config();

        $this->assertTrue($config['enabled']);
        $this->assertSame('ws://example.com/ws/third-party/script', $config['script_ws_url']);
        $this->assertSame('script-token', $config['script_token']);
        $this->assertSame('ws://example.com/ws/third-party/script?token=script-token', $config['script_full_url']);
        $this->assertSame('', $config['sign_secret']);
    }
}
