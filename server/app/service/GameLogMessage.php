<?php

namespace app\service;

use JsonException;

final class GameLogMessage
{
    public const PREFIX = '[[I18N]]';

    public static function localized(string $level, string $key, array $params = []): string
    {
        $level = strtoupper(trim($level));
        if (!in_array($level, ['INFO', 'WARN', 'ERROR'], true)) {
            throw new \InvalidArgumentException('Unsupported game log level: ' . $level);
        }
        if (!preg_match('/^client\.logs\.system\.[a-z0-9_]+$/', $key)) {
            throw new \InvalidArgumentException('Invalid game log translation key: ' . $key);
        }

        try {
            $payload = json_encode([
                'key' => $key,
                'params' => self::normalizeParams($params),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \InvalidArgumentException('Game log translation parameters are not JSON encodable', 0, $e);
        }

        return '[' . $level . '] ' . self::PREFIX . $payload;
    }

    private static function normalizeParams(array $params): array
    {
        $normalized = [];
        foreach ($params as $name => $value) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException('Invalid game log translation parameter name');
            }
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Game log translation parameters must be scalar');
            }
            $normalized[$name] = $value === null ? '' : (string)$value;
        }
        return $normalized;
    }
}
