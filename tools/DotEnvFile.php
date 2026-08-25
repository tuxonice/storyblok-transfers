<?php

declare(strict_types=1);


/**
 * Minimal .env reader.
 *
 * The package ships no dotenv reader - bin/generate reads getenv() and Compose
 * is what injects the values. Asking a developer tool to be run through
 * `set -a && . ./.env` would be friction for nothing, so parse the file here.
 * Nothing is written back into the environment: the values are returned, and
 * Configuration decides what wins.
 */
final class DotEnvFile
{
    /**
     * @return array<string, string> Empty when the file does not exist.
     */
    public static function parse(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        return $contents === false ? [] : self::parseContents($contents);
    }

    /**
     * @return array<string, string>
     */
    public static function parseContents(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $values[trim($key)] = self::unquote(trim($value));
        }

        return $values;
    }

    /**
     * Only a matched pair is stripped, so a value that merely contains a quote
     * survives intact.
     */
    private static function unquote(string $value): string
    {
        if (strlen($value) < 2) {
            return $value;
        }

        $first = $value[0];

        if (($first === '"' || $first === "'") && $first === $value[-1]) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
