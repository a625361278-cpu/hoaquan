<?php

namespace app\support;

use RuntimeException;
use support\Request;

class I18n
{
    public const DEFAULT_LOCALE = 'zh_CN';
    public const COOKIE_NAME = 'gameassist_locale';
    public const SUPPORTED_LOCALES = ['zh_CN', 'vi'];

    private static array $messages = [];
    private static array $messageMtimes = [];

    public static function normalizeLocale(?string $locale): string
    {
        $locale = trim((string)$locale);
        return in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : self::DEFAULT_LOCALE;
    }

    public static function localeFromRequest(?Request $request = null): string
    {
        $request ??= request();
        $queryLocale = $request->get('lang') ?: $request->get('locale');
        if ($queryLocale) {
            return self::normalizeLocale((string)$queryLocale);
        }

        $headerLocale = $request->header('x-locale', '');
        if ($headerLocale) {
            return self::normalizeLocale($headerLocale);
        }

        $cookieLocale = $request->cookie(self::COOKIE_NAME, '');
        if ($cookieLocale) {
            return self::normalizeLocale((string)$cookieLocale);
        }

        return self::DEFAULT_LOCALE;
    }

    public static function t(string $key, array $parameters = [], ?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);
        $messages = self::messages($locale);
        if (!array_key_exists($key, $messages)) {
            throw new RuntimeException("Missing translation key [{$key}] for locale [{$locale}]");
        }

        $message = (string)$messages[$key];
        foreach ($parameters as $name => $value) {
            $message = str_replace('{' . $name . '}', (string)$value, $message);
        }
        return $message;
    }

    public static function messages(?string $locale = null): array
    {
        $locale = self::normalizeLocale($locale);
        $path = base_path("resource/translations/{$locale}/messages.json");
        if (!is_file($path)) {
            throw new RuntimeException("Translation package not found: {$path}");
        }

        clearstatcache(true, $path);
        $mtime = filemtime($path) ?: 0;
        if (!isset(self::$messages[$locale]) || (self::$messageMtimes[$locale] ?? null) !== $mtime) {
            $messages = json_decode((string)file_get_contents($path), true);
            if (!is_array($messages)) {
                throw new RuntimeException("Translation package is invalid JSON: {$path}");
            }

            self::$messages[$locale] = $messages;
            self::$messageMtimes[$locale] = $mtime;
        }

        return self::$messages[$locale];
    }
}
