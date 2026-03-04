<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Idempotency Key Retention
    |--------------------------------------------------------------------------
    |
    | Completed idempotent request fingerprints are kept for a bounded window
    | so retries can replay safely without unbounded table growth.
    |
    */
    'ttl_minutes' => (int) env('IDEMPOTENCY_TTL_MINUTES', 1440),
];
