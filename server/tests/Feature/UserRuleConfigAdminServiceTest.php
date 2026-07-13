<?php

namespace tests\Feature;

use app\service\SystemSettingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use plugin\admin\app\service\UserRuleConfigAdminService;
use RuntimeException;

class UserRuleConfigAdminServiceTest extends TestCase
{
    public function testConfigReturnsCurrentRegistrationRewardPoints(): void
    {
        $service = new UserRuleConfigAdminService($this->settingsWithValue('8'));

        $this->assertSame(8, $service->config()['registration_reward_points']);
    }

    public function testSavePersistsZeroAndConfiguredValue(): void
    {
        $settings = $this->settingsWithValue(null);
        $service = new UserRuleConfigAdminService($settings);

        $service->save(['registration_reward_points' => '0']);
        $this->assertSame('0', $settings->saved['registration_reward_points']);

        $service->save(['registration_reward_points' => '1000']);
        $this->assertSame('1000', $settings->saved['registration_reward_points']);
    }

    #[DataProvider('invalidValueProvider')]
    public function testSaveRejectsInvalidValue(string $value): void
    {
        $service = new UserRuleConfigAdminService($this->settingsWithValue(null));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('新用户注册赠送点数必须是0至1000的整数');
        $service->save(['registration_reward_points' => $value]);
    }

    public static function invalidValueProvider(): array
    {
        return [
            'empty' => [''],
            'negative' => ['-1'],
            'decimal' => ['1.5'],
            'above maximum' => ['1001'],
            'text' => ['one'],
        ];
    }

    private function settingsWithValue(?string $value): SystemSettingService
    {
        return new class($value) extends SystemSettingService {
            public array $saved = [];

            public function __construct(private ?string $value)
            {
            }

            public function get(string $name, string $default = ''): string
            {
                return $this->value ?? $default;
            }

            public function saveSettings(array $settings): void
            {
                $this->saved = $settings;
            }
        };
    }
}
