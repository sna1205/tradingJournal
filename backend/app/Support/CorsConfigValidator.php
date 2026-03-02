<?php

namespace App\Support;

use RuntimeException;

final class CorsConfigValidator
{
    /**
     * @param array<int, string> $allowedOrigins
     * @param array<int, string> $allowedOriginPatterns
     */
    public static function validate(
        string $appEnv,
        array $allowedOrigins,
        array $allowedOriginPatterns = [],
        bool $supportsCredentials = true,
        bool $statefulApi = true
    ): void
    {
        if (strtolower($appEnv) !== 'production') {
            return;
        }

        $origins = self::normalizeList($allowedOrigins);
        $patterns = self::normalizeList($allowedOriginPatterns);

        if (count($origins) === 0) {
            throw new RuntimeException(
                'Invalid CORS configuration: CORS_ALLOWED_ORIGINS must be a comma-separated list of explicit https origins in production.'
            );
        }

        if ($statefulApi) {
            if (count($patterns) > 0) {
                throw new RuntimeException(
                    'Invalid CORS configuration: CORS_ALLOWED_ORIGIN_PATTERNS is not allowed in production when SANCTUM_STATEFUL_API=true. Use explicit CORS_ALLOWED_ORIGINS only.'
                );
            }

            if ($supportsCredentials !== true) {
                throw new RuntimeException(
                    'Invalid CORS configuration: CORS_SUPPORTS_CREDENTIALS must be true in production for Sanctum SPA cookie authentication.'
                );
            }
        }

        foreach ($origins as $origin) {
            if (str_contains($origin, '*')) {
                throw new RuntimeException(
                    'Invalid CORS configuration: wildcard origins are not allowed in production.'
                );
            }

            if (!self::isExactHttpsOrigin($origin)) {
                throw new RuntimeException(
                    "Invalid CORS configuration: origin [{$origin}] must be an exact https origin (for example https://app.example.com)."
                );
            }
        }
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private static function normalizeList(array $values): array
    {
        $normalized = array_map(
            static fn (string $value): string => rtrim(trim($value), '/'),
            $values
        );

        return array_values(array_filter($normalized, static fn (string $value): bool => $value !== ''));
    }

    private static function isExactHttpsOrigin(string $origin): bool
    {
        $parts = parse_url($origin);
        if (!is_array($parts)) {
            return false;
        }

        if (($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        if (!isset($parts['host']) || trim((string) $parts['host']) === '') {
            return false;
        }

        if (array_key_exists('path', $parts) && $parts['path'] !== '') {
            return false;
        }
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && (!is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535)) {
            return false;
        }

        return true;
    }
}
