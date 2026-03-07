<?php

namespace App\Support;

use RuntimeException;

final class AuthLifetimeConfigValidator
{
    public static function validate(int $sessionLifetimeMinutes, int $sanctumExpirationMinutes): void
    {
        if ($sessionLifetimeMinutes <= 0) {
            throw new RuntimeException(
                'Invalid auth configuration: SESSION_LIFETIME must be a positive number of minutes.'
            );
        }

        if ($sanctumExpirationMinutes <= 0) {
            throw new RuntimeException(
                'Invalid auth configuration: SANCTUM_EXPIRATION_MINUTES must be a positive number of minutes.'
            );
        }
    }
}
