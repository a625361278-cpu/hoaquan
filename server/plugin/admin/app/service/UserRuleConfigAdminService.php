<?php

namespace plugin\admin\app\service;

use app\service\SystemSettingService;
use app\support\I18n;
use RuntimeException;

class UserRuleConfigAdminService
{
    public function __construct(
        private ?SystemSettingService $settings = null,
        private string $locale = I18n::DEFAULT_LOCALE
    ) {
        $this->settings ??= new SystemSettingService();
        $this->locale = I18n::normalizeLocale($this->locale);
    }

    public function config(): array
    {
        return [
            'registration_reward_points' => $this->settings->registrationRewardPoints(),
        ];
    }

    public function save(array $payload): void
    {
        $points = $this->validatePoints($payload['registration_reward_points'] ?? '');
        $this->settings->saveSettings([
            'registration_reward_points' => (string)$points,
        ]);
    }

    private function validatePoints(mixed $value): int
    {
        $raw = trim((string)$value);
        if (!preg_match('/^\d+$/', $raw)) {
            throw $this->invalidPoints();
        }

        $points = (int)$raw;
        if ($points < SystemSettingService::MIN_REGISTRATION_REWARD_POINTS
            || $points > SystemSettingService::MAX_REGISTRATION_REWARD_POINTS) {
            throw $this->invalidPoints();
        }
        return $points;
    }

    private function invalidPoints(): RuntimeException
    {
        return new RuntimeException(I18n::t('admin.user_rules.registration_reward_points_invalid', [
            'min' => SystemSettingService::MIN_REGISTRATION_REWARD_POINTS,
            'max' => SystemSettingService::MAX_REGISTRATION_REWARD_POINTS,
        ], $this->locale));
    }
}
