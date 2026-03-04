<?php

namespace App\Support;

use RuntimeException;

final class LocalDatabaseConfigValidator
{
    public static function validate(string $appEnv, string $defaultConnection): void
    {
        if (strtolower(trim($appEnv)) !== 'local') {
            return;
        }

        if (strtolower(trim($defaultConnection)) === 'sqlite') {
            return;
        }

        throw new RuntimeException(
            'Invalid local database configuration: DB_CONNECTION must be sqlite in local environment.'
        );
    }
}
