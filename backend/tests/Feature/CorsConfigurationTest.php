<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_cors_configuration_does_not_use_wildcards_for_methods_or_headers(): void
    {
        $allowedMethods = config('cors.allowed_methods', []);
        $allowedHeaders = config('cors.allowed_headers', []);

        $this->assertIsArray($allowedMethods);
        $this->assertIsArray($allowedHeaders);
        $this->assertNotContains('*', $allowedMethods);
        $this->assertNotContains('*', $allowedHeaders);
    }

    public function test_cors_configuration_defaults_to_explicit_origin_list(): void
    {
        $allowedOrigins = config('cors.allowed_origins', []);

        $this->assertIsArray($allowedOrigins);
        $this->assertNotContains('*', $allowedOrigins);
    }
}
