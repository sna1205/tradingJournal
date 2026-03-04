<?php

namespace Tests\Feature;

use App\Support\ApiErrorResponder;
use Tests\TestCase;

class SanctumCsrfCookieAvailabilityTest extends TestCase
{
    public function test_csrf_cookie_succeeds_with_file_sessions_even_if_database_is_unreachable(): void
    {
        config([
            'session.driver' => 'file',
            'database.default' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => 1,
            'database.connections.mysql.database' => 'trading_journal',
            'database.connections.mysql.username' => 'trading',
            'database.connections.mysql.password' => 'invalid',
            'database.connections.mysql.options' => [],
        ]);

        $response = $this->get('/sanctum/csrf-cookie', [
            'Accept' => 'application/json',
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/login',
        ]);

        $response->assertNoContent();
    }

    public function test_csrf_cookie_returns_service_unavailable_when_database_session_store_is_unreachable(): void
    {
        config([
            'session.driver' => 'database',
            'session.table' => 'sessions',
            'database.default' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => 1,
            'database.connections.mysql.database' => 'trading_journal',
            'database.connections.mysql.username' => 'trading',
            'database.connections.mysql.password' => 'invalid',
            'database.connections.mysql.options' => [],
        ]);

        $response = $this->get('/sanctum/csrf-cookie', [
            'Accept' => 'application/json',
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/login',
        ]);

        $response->assertStatus(503);
        $response->assertHeader('X-Error-Contract', 'v2');
        $response->assertJsonPath('error.version', ApiErrorResponder::CONTRACT_VERSION);
        $response->assertJsonPath('error.code', 'database_unavailable');
    }
}
