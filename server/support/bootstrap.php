<?php

if (!function_exists('app_env')) {
    function app_env(string $key, mixed $default = null): mixed
    {
        static $loaded = false;
        if (!$loaded) {
            $envFile = dirname(__DIR__) . '/.env';
            if (is_file($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$name, $value] = explode('=', $line, 2);
                    $name = trim($name);
                    $value = trim($value);
                    if ($value !== '' && (
                        ($value[0] === '"' && str_ends_with($value, '"')) ||
                        ($value[0] === "'" && str_ends_with($value, "'"))
                    )) {
                        $value = substr($value, 1, -1);
                    }
                    if ($name !== '' && getenv($name) === false) {
                        putenv($name . '=' . $value);
                        $_ENV[$name] = $value;
                    }
                }
            }
            $loaded = true;
        }

        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}

require_once __DIR__ . '/../vendor/workerman/webman-framework/src/support/bootstrap.php';
