<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum's EnsureFrontendRequestsAreStateful middleware decides
        // whether to inject session middleware based on the Origin /
        // Referer header. PHP's test runner does not set either by default,
        // so without this every test request would bypass the stateful API
        // path and any code that touches $request->session() would crash
        // with "Session store not set on request". Setting the default
        // header here mirrors what every browser sends in real life and
        // matches the entries in config/sanctum.php → stateful.
        $this->withHeader('Origin', 'http://localhost');
    }
}
