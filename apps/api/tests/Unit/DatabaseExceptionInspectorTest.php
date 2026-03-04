<?php

namespace Tests\Unit;

use App\Support\DatabaseExceptionInspector;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseExceptionInspectorTest extends TestCase
{
    public function test_detects_mysql_connection_refused_from_query_exception_chain(): void
    {
        $exception = new QueryException(
            'mysql',
            'select 1',
            [],
            new RuntimeException('SQLSTATE[HY000] [2002] Connection refused', 2002)
        );

        $this->assertTrue(DatabaseExceptionInspector::isConnectionIssue($exception));
    }

    public function test_rejects_non_connectivity_query_errors(): void
    {
        $exception = new QueryException(
            'mysql',
            'select * from users',
            [],
            new RuntimeException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'users' doesn't exist", 1146)
        );

        $this->assertFalse(DatabaseExceptionInspector::isConnectionIssue($exception));
    }
}
