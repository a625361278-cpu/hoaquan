<?php

namespace tests\Feature;

use app\service\SystemSettingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SystemSettingServiceTest extends TestCase
{
    public function testGameAccountMaxCountUsesDefaultWhenSettingIsMissing(): void
    {
        $service = $this->serviceWithValue(null);

        $this->assertSame(3, $service->gameAccountMaxCount());
    }

    public function testGameAccountMaxCountReturnsConfiguredValue(): void
    {
        $service = $this->serviceWithValue('12');

        $this->assertSame(12, $service->gameAccountMaxCount());
    }

    #[DataProvider('invalidValueProvider')]
    public function testGameAccountMaxCountRejectsInvalidStoredValue(string $value): void
    {
        $service = $this->serviceWithValue($value);

        $this->expectException(RuntimeException::class);
        $service->gameAccountMaxCount();
    }

    public static function invalidValueProvider(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'above maximum' => ['101'],
            'decimal' => ['3.5'],
            'negative' => ['-1'],
            'text' => ['three'],
        ];
    }

    public function testRegistrationRewardPointsUsesDefaultWhenSettingIsMissing(): void
    {
        $this->assertSame(1, $this->serviceWithValue(null)->registrationRewardPoints());
    }

    public function testRegistrationRewardPointsAcceptsZeroAndConfiguredValue(): void
    {
        $this->assertSame(0, $this->serviceWithValue('0')->registrationRewardPoints());
        $this->assertSame(25, $this->serviceWithValue('25')->registrationRewardPoints());
    }

    public function testSupportedLoginMethodsFollowSocialSwitches(): void
    {
        $service = new class extends SystemSettingService {
            public function thirdPartyConfig(): array
            {
                return ['facebook_login_enabled' => false, 'google_login_enabled' => true];
            }
        };

        $this->assertSame([1, 3], $service->supportedLoginMethods());
    }

    #[DataProvider('invalidRegistrationRewardProvider')]
    public function testRegistrationRewardPointsRejectsInvalidStoredValue(string $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->serviceWithValue($value)->registrationRewardPoints();
    }

    public static function invalidRegistrationRewardProvider(): array
    {
        return [
            'empty' => [''],
            'above maximum' => ['1001'],
            'decimal' => ['1.5'],
            'negative' => ['-1'],
            'text' => ['one'],
        ];
    }

    public function testInviteRewardMinimumRoleLevelUsesDefaultAndAcceptsBoundaries(): void
    {
        $this->assertSame(30, $this->serviceWithValue(null)->inviteRewardMinRoleLevel());
        $this->assertSame(1, $this->serviceWithValue('1')->inviteRewardMinRoleLevel());
        $this->assertSame(9999, $this->serviceWithValue('9999')->inviteRewardMinRoleLevel());
    }

    #[DataProvider('invalidInviteRewardMinimumRoleLevelProvider')]
    public function testInviteRewardMinimumRoleLevelRejectsInvalidStoredValue(string $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->serviceWithValue($value)->inviteRewardMinRoleLevel();
    }

    public static function invalidInviteRewardMinimumRoleLevelProvider(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'above maximum' => ['10000'],
            'decimal' => ['30.5'],
            'negative' => ['-1'],
            'text' => ['thirty'],
        ];
    }

    public function testPaymentRechargeAmountUsesDefaultAndAcceptsBoundaries(): void
    {
        $this->assertSame(149000, $this->serviceWithValue(null)->paymentRechargeAmountVnd());
        $this->assertSame(1, $this->serviceWithValue('1')->paymentRechargeAmountVnd());
        $this->assertSame(999999999, $this->serviceWithValue('999999999')->paymentRechargeAmountVnd());
    }

    #[DataProvider('invalidPaymentRechargeAmountProvider')]
    public function testPaymentRechargeAmountRejectsInvalidStoredValue(string $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->serviceWithValue($value)->paymentRechargeAmountVnd();
    }

    public static function invalidPaymentRechargeAmountProvider(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'above maximum' => ['1000000000'],
            'decimal' => ['149000.50'],
            'negative' => ['-1'],
            'text' => ['one hundred forty-nine thousand'],
        ];
    }

    private function serviceWithValue(?string $value): SystemSettingService
    {
        return new class($value) extends SystemSettingService {
            public function __construct(private ?string $value)
            {
            }

            public function get(string $name, string $default = ''): string
            {
                return $this->value ?? $default;
            }
        };
    }
}
