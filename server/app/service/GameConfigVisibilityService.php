<?php

namespace app\service;

use app\support\I18n;
use JsonException;
use RuntimeException;

class GameConfigVisibilityService
{
    public const SETTING_NAME = 'game_config_visibility_overrides';

    private ?array $catalog = null;
    private ?array $itemsByPath = null;

    public function __construct(
        private ?SystemSettingService $settings = null,
        private string $locale = I18n::DEFAULT_LOCALE,
        private ?string $catalogPath = null
    ) {
        $this->settings ??= new SystemSettingService();
        $this->locale = I18n::normalizeLocale($this->locale);
        $this->catalogPath ??= dirname(__DIR__, 3) . '/shared/game-config-visibility.json';
    }

    public function hiddenPaths(): array
    {
        $visibility = $this->visibilityByPath();
        return array_keys(array_filter($visibility, static fn (bool $visible): bool => !$visible));
    }

    public function visibilityByPath(): array
    {
        $visibility = [];
        foreach ($this->itemsByPath() as $path => $item) {
            $visibility[$path] = $item['default_visible'];
        }

        foreach ($this->loadOverrides() as $path => $visible) {
            $visibility[$path] = $visible;
        }
        $this->assertDependencyVisibility($visibility, $this->invalidStoredSetting());
        return $visibility;
    }

    public function adminCatalog(): array
    {
        $visibility = $this->visibilityByPath();
        $tabs = [];
        foreach ($this->catalog()['tabs'] as $tab) {
            $groups = [];
            foreach ($tab['groups'] as $group) {
                $items = [];
                foreach ($group['items'] as $item) {
                    $items[] = [
                        'path' => $item['path'],
                        'label' => I18n::t($item['labelKey'], [], $this->locale),
                        'label_key' => $item['labelKey'],
                        'type' => $item['type'],
                        'depends_on_paths' => $item['dependsOnPaths'],
                        'default_visible' => $item['defaultVisible'],
                        'visible' => $visibility[$item['path']],
                    ];
                }
                $groups[] = [
                    'key' => $group['key'],
                    'title' => I18n::t($group['titleKey'], [], $this->locale),
                    'title_key' => $group['titleKey'],
                    'items' => $items,
                ];
            }
            $tabs[] = [
                'key' => $tab['key'],
                'title' => I18n::t($tab['titleKey'], [], $this->locale),
                'title_key' => $tab['titleKey'],
                'groups' => $groups,
            ];
        }

        return [
            'item_count' => count($this->itemsByPath()),
            'visible_count' => count(array_filter($visibility)),
            'hidden_count' => count(array_filter($visibility, static fn (bool $visible): bool => !$visible)),
            'tabs' => $tabs,
        ];
    }

    public function saveVisibility(array $visibility): void
    {
        $items = $this->itemsByPath();
        if (count($visibility) !== count($items)
            || array_diff_key($items, $visibility) !== []
            || array_diff_key($visibility, $items) !== []) {
            throw $this->invalidPayload();
        }

        $overrides = [];
        foreach ($items as $path => $item) {
            if (!is_bool($visibility[$path])) {
                throw $this->invalidPayload();
            }
            if ($visibility[$path] !== $item['default_visible']) {
                $overrides[$path] = $visibility[$path];
            }
        }
        $this->assertDependencyVisibility($visibility, $this->invalidPayload());

        try {
            $encoded = json_encode(
                $overrides === [] ? (object)[] : $overrides,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(I18n::t('admin.game_config_visibility.payload_invalid', [], $this->locale), 0, $exception);
        }
        $this->settings->saveSettings([self::SETTING_NAME => $encoded]);
    }

    private function loadOverrides(): array
    {
        $raw = trim($this->settings->get(self::SETTING_NAME, '{}'));
        if ($raw === '' || !str_starts_with($raw, '{') || !str_ends_with($raw, '}')) {
            throw $this->invalidStoredSetting();
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(I18n::t('api.game.config_visibility_invalid', [], $this->locale), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw $this->invalidStoredSetting();
        }

        $items = $this->itemsByPath();
        foreach ($decoded as $path => $visible) {
            if (!is_string($path) || !array_key_exists($path, $items) || !is_bool($visible)) {
                throw $this->invalidStoredSetting();
            }
        }
        return $decoded;
    }

    private function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }
        if (!is_file($this->catalogPath) || !is_readable($this->catalogPath)) {
            throw new RuntimeException(I18n::t('api.game.config_visibility_invalid', [], $this->locale));
        }
        try {
            $catalog = json_decode((string)file_get_contents($this->catalogPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(I18n::t('api.game.config_visibility_invalid', [], $this->locale), 0, $exception);
        }
        if (!is_array($catalog)
            || ($catalog['version'] ?? null) !== 1
            || ($catalog['itemCount'] ?? null) !== 196
            || ($catalog['defaultHiddenCount'] ?? null) !== 33
            || !is_array($catalog['tabs'] ?? null)) {
            throw new RuntimeException(I18n::t('api.game.config_visibility_invalid', [], $this->locale));
        }
        $this->catalog = $catalog;
        $this->itemsByPath();
        return $this->catalog;
    }

    private function itemsByPath(): array
    {
        if ($this->itemsByPath !== null) {
            return $this->itemsByPath;
        }
        $catalog = $this->catalog ?? $this->catalog();
        $items = [];
        foreach ($catalog['tabs'] as $tab) {
            if (!is_string($tab['key'] ?? null)
                || !is_string($tab['titleKey'] ?? null)
                || !is_array($tab['groups'] ?? null)) {
                throw new RuntimeException(I18n::t('api.game.config_visibility_invalid', [], $this->locale));
            }
            foreach ($tab['groups'] as $group) {
                if (!is_string($group['key'] ?? null)
                    || !is_string($group['titleKey'] ?? null)
                    || !is_array($group['items'] ?? null)) {
                    throw new RuntimeException(I18n::t('api.game.config_visibility_invalid', [], $this->locale));
                }
                foreach ($group['items'] as $item) {
                    $path = $item['path'] ?? null;
                    if (!is_string($path)
                        || $path === ''
                        || isset($items[$path])
                        || !is_string($item['labelKey'] ?? null)
                        || !is_string($item['type'] ?? null)
                        || !is_array($item['dependsOnPaths'] ?? null)
                        || !is_bool($item['defaultVisible'] ?? null)) {
                        throw new RuntimeException(I18n::t('api.game.config_visibility_invalid', [], $this->locale));
                    }
                    $items[$path] = [
                        'default_visible' => $item['defaultVisible'],
                        'depends_on_paths' => $item['dependsOnPaths'],
                    ];
                }
            }
        }
        if (count($items) !== 196) {
            throw new RuntimeException(I18n::t('api.game.config_visibility_invalid', [], $this->locale));
        }
        foreach ($items as $item) {
            foreach ($item['depends_on_paths'] as $dependencyPath) {
                if (!is_string($dependencyPath) || !isset($items[$dependencyPath])) {
                    throw new RuntimeException(I18n::t('api.game.config_visibility_invalid', [], $this->locale));
                }
            }
        }
        $this->itemsByPath = $items;
        return $this->itemsByPath;
    }

    private function invalidStoredSetting(): RuntimeException
    {
        return new RuntimeException(I18n::t('api.game.config_visibility_invalid', [], $this->locale));
    }

    private function invalidPayload(): RuntimeException
    {
        return new RuntimeException(I18n::t('admin.game_config_visibility.payload_invalid', [], $this->locale));
    }

    private function assertDependencyVisibility(array $visibility, RuntimeException $exception): void
    {
        foreach ($this->itemsByPath() as $path => $item) {
            if (!$visibility[$path]) {
                continue;
            }
            foreach ($item['depends_on_paths'] as $dependencyPath) {
                if (!$visibility[$dependencyPath]) {
                    throw $exception;
                }
            }
        }
    }
}
