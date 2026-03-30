<?php

declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    static $loaded = false;
    static $values = [];

    if (!$loaded) {
        $loaded = true;
        $envPath = dirname(__DIR__) . '/.env';

        if (is_file($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $envKey = trim($parts[0]);
                    $envValue = trim($parts[1]);

                    if (
                        (str_starts_with($envValue, '"') && str_ends_with($envValue, '"')) ||
                        (str_starts_with($envValue, "'") && str_ends_with($envValue, "'"))
                    ) {
                        $envValue = substr($envValue, 1, -1);
                    }

                    $values[$envKey] = $envValue;
                }
            }
        }
    }

    return $values[$key] ?? $default;
}
