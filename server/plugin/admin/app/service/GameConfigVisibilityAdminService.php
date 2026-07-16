<?php

namespace plugin\admin\app\service;

use app\service\GameConfigVisibilityService;
use app\support\I18n;
use JsonException;
use RuntimeException;

class GameConfigVisibilityAdminService
{
    public function __construct(
        private ?GameConfigVisibilityService $visibility = null,
        private string $locale = I18n::DEFAULT_LOCALE
    ) {
        $this->locale = I18n::normalizeLocale($this->locale);
        $this->visibility ??= new GameConfigVisibilityService(locale: $this->locale);
    }

    public function config(): array
    {
        return $this->visibility->adminCatalog();
    }

    public function save(array $payload): void
    {
        $raw = trim((string)($payload['visibility_json'] ?? ''));
        if ($raw === '' || !str_starts_with($raw, '{') || !str_ends_with($raw, '}')) {
            throw $this->invalidPayload();
        }
        try {
            $visibility = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(I18n::t('admin.game_config_visibility.payload_invalid', [], $this->locale), 0, $exception);
        }
        if (!is_array($visibility)) {
            throw $this->invalidPayload();
        }
        $this->visibility->saveVisibility($visibility);
    }

    private function invalidPayload(): RuntimeException
    {
        return new RuntimeException(I18n::t('admin.game_config_visibility.payload_invalid', [], $this->locale));
    }
}
