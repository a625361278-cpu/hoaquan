<?php

namespace tests\Feature;

use app\service\SystemSettingService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'game_account_max_count' => '5',
            'facebook_login_enabled' => '1',
            'google_login_enabled' => '0',
        ]);

        $this->assertSame('1', $settings->saved['third_party_enabled']);
        $this->assertSame('ws://example.com/ws/third-party/script', $settings->saved['third_party_script_ws_url']);
        $this->assertSame('script-token', $settings->saved['third_party_script_token']);
        $this->assertSame('', $settings->saved['third_party_sign_secret']);
        $this->assertSame('websocket', $settings->saved['third_party_transport']);
        $this->assertSame('5', $settings->saved['game_account_max_count']);
        $this->assertSame('1', $settings->saved['facebook_login_enabled']);
        $this->assertSame('0', $settings->saved['google_login_enabled']);
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
            'game_account_max_count' => '3',
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
                    'game_account_max_count' => '7',
                    'facebook_login_enabled' => '0',
                    'google_login_enabled' => '1',
                ];
            }
        });

        $config = $service->config();

        $this->assertTrue($config['enabled']);
        $this->assertSame('ws://example.com/ws/third-party/script', $config['script_ws_url']);
        $this->assertSame('script-token', $config['script_token']);
        $this->assertSame('ws://example.com/ws/third-party/script?token=script-token', $config['script_full_url']);
        $this->assertSame('', $config['sign_secret']);
        $this->assertSame(7, $config['game_account_max_count']);
        $this->assertFalse($config['facebook_login_enabled']);
        $this->assertTrue($config['google_login_enabled']);
    }

    public function testConfigUsesDefaultGameAccountLimitWhenSettingIsMissing(): void
    {
        $service = new ThirdPartyConfigAdminService(new class extends SystemSettingService {
            public function thirdPartyRawSettings(): array
            {
                return [];
            }
        });

        $this->assertSame(3, $service->config()['game_account_max_count']);
        $this->assertTrue($service->config()['facebook_login_enabled']);
        $this->assertTrue($service->config()['google_login_enabled']);
    }

    #[DataProvider('invalidGameAccountMaxCountProvider')]
    public function testSaveRejectsInvalidGameAccountMaxCount(string $value): void
    {
        $service = new ThirdPartyConfigAdminService(new class extends SystemSettingService {
            public function saveSettings(array $settings): void
            {
                throw new RuntimeException('Invalid setting must not be saved.');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('每个用户最多游戏账号数必须是1至100的整数');
        $service->save([
            'third_party_enabled' => '0',
            'third_party_script_ws_url' => '',
            'third_party_script_token' => '',
            'third_party_sign_secret' => '',
            'game_account_max_count' => $value,
        ]);
    }

    public static function invalidGameAccountMaxCountProvider(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'above maximum' => ['101'],
            'decimal' => ['3.5'],
            'negative' => ['-1'],
        ];
    }
}
