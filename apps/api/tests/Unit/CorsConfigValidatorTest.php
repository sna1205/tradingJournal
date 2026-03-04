<?php

namespace Tests\Unit;

use App\Support\CorsConfigValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CorsConfigValidatorTest extends TestCase
{
    public function test_production_rejects_missing_allowed_origins(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CORS_ALLOWED_ORIGINS must be a comma-separated list');

        CorsConfigValidator::validate('production', [], []);
    }

    public function test_production_rejects_wildcard_origin(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('wildcard origins are not allowed');

        CorsConfigValidator::validate('production', ['*'], []);
    }

    public function test_production_rejects_origin_patterns(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CORS_ALLOWED_ORIGIN_PATTERNS is not allowed in production');

        CorsConfigValidator::validate('production', ['https://app.example.com'], ['^https://.*$']);
    }

    public function test_production_accepts_explicit_https_origins_only(): void
    {
        CorsConfigValidator::validate('production', [
            'https://app.example.com',
            'https://admin.example.com:8443',
        ], []);

        $this->expectNotToPerformAssertions();
    }

    public function test_non_production_allows_wildcard(): void
    {
        CorsConfigValidator::validate('local', ['*'], []);

        $this->expectNotToPerformAssertions();
    }

    public function test_production_requires_cors_credentials_support(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CORS_SUPPORTS_CREDENTIALS must be true');

        CorsConfigValidator::validate('production', ['https://app.example.com'], [], false);
    }
}
