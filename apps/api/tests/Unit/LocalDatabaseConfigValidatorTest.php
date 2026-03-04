<?php

namespace Tests\Unit;

use App\Support\LocalDatabaseConfigValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LocalDatabaseConfigValidatorTest extends TestCase
{
    public function test_local_environment_rejects_non_sqlite_default_connection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB_CONNECTION must be sqlite in local environment');

        LocalDatabaseConfigValidator::validate('local', 'mysql');
    }

    public function test_local_environment_accepts_sqlite_default_connection(): void
    {
        LocalDatabaseConfigValidator::validate('local', 'sqlite');
        $this->expectNotToPerformAssertions();
    }

    public function test_non_local_environment_allows_any_connection(): void
    {
        LocalDatabaseConfigValidator::validate('production', 'mysql');
        $this->expectNotToPerformAssertions();
    }
}
