<?php

namespace tests\Feature;

use app\service\SystemSettingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use plugin\admin\app\service\UserRuleConfigAdminService;
use RuntimeException;

class UserRuleConfigAdminServiceTest extends TestCase
{
    public function testConfigReturnsCurrentUserRuleValues(): void
    {
        $service = new UserRuleConfigAdminService($this->settingsWithValues([
            'registration_reward_points' => '8',
            'invite_reward_min_role_level' => '35',
        ]));

        $this->assertSame(8, $service->config()['registration_reward_points']);
        $this->assertSame(35, $service->config()['invite_reward_min_role_level']);
    }

    public function testSavePersistsZeroAndConfiguredValue(): void
    {
        $settings = $this->settingsWithValues([]);
        $service = new UserRuleConfigAdminService($settings);

        $service->save(['registration_reward_points' => '0', 'invite_reward_min_role_level' => '1']);
        $this->assertSame('0', $settings->saved['registration_reward_points']);
        $this->assertSame('1', $settings->saved['invite_reward_min_role_level']);

        $service->save(['registration_reward_points' => '1000', 'invite_reward_min_role_level' => '9999']);
        $this->assertSame('1000', $settings->saved['registration_reward_points']);
        $this->assertSame('9999', $settings->saved['invite_reward_min_role_level']);
    }

    #[DataProvider('invalidValueProvider')]
    public function testSaveRejectsInvalidValue(string $value): void
    {
        $service = new UserRuleConfigAdminService($this->settingsWithValues([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('新用户注册赠送点数必须是0至1000的整数');
        $service->save(['registration_reward_points' => $value, 'invite_reward_min_role_level' => '30']);
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

    #[DataProvider('invalidMinRoleLevelProvider')]
    public function testSaveRejectsInvalidMinimumRoleLevel(string $value): void
    {
        $service = new UserRuleConfigAdminService($this->settingsWithValues([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('邀请奖励最低角色等级必须是1至9999的整数');
        $service->save(['registration_reward_points' => '1', 'invite_reward_min_role_level' => $value]);
    }

    public static function invalidMinRoleLevelProvider(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'negative' => ['-1'],
            'decimal' => ['30.5'],
            'above maximum' => ['10000'],
            'text' => ['thirty'],
        ];
    }

    private function settingsWithValues(array $values): SystemSettingService
    {
        return new class($values) extends SystemSettingService {
            public array $saved = [];

            public function __construct(private array $values)
            {
            }

            public function get(string $name, string $default = ''): string
            {
                return array_key_exists($name, $this->values) ? (string)$this->values[$name] : $default;
            }

            public function saveSettings(array $settings): void
            {
                $this->saved = $settings;
            }
        };
    }
}
