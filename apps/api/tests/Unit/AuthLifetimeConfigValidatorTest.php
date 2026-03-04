<?php

namespace Tests\Unit;

use App\Support\AuthLifetimeConfigValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AuthLifetimeConfigValidatorTest extends TestCase
{
    public function test_rejects_non_positive_session_lifetime(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SESSION_LIFETIME must be a positive number of minutes');

        AuthLifetimeConfigValidator::validate(0, 120);
    }

    public function test_rejects_non_positive_sanctum_expiration(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SANCTUM_EXPIRATION_MINUTES must be a positive number of minutes');

        AuthLifetimeConfigValidator::validate(120, 0);
    }

    public function test_accepts_finite_positive_lifetimes(): void
    {
        AuthLifetimeConfigValidator::validate(120, 120);

        $this->expectNotToPerformAssertions();
    }
}
