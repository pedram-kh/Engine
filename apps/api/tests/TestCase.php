<?php

declare(strict_types=1);

namespace Tests;

use App\TestHelpers\Http\Middleware\ApplyTestClock;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

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

    protected function tearDown(): void
    {
        // Carbon::setTestNow is process-global static state. Pest reuses a
        // single PHP process for the whole suite, so a test that pins Carbon
        // and forgets to reset would leak that pin into the next test. Reset
        // unconditionally — tests that need a pinned clock re-pin in their
        // own setup.
        Carbon::setTestNow();

        // Likewise reset the App\TestHelpers\Http\Middleware\ApplyTestClock
        // pinning tracker so the per-process flag does not bleed across
        // tests in either direction. See ApplyTestClock's class docblock
        // for why we track pinning ourselves rather than calling
        // setTestNow() unconditionally inside the middleware.
        ApplyTestClock::resetPinningTracker();

        parent::tearDown();
    }

    /**
     * RefreshDatabase runs `migrate:fresh` against whatever DB_DATABASE points at, which on the
     * Postgres leg (phpunit.pgsql.xml, composer test:pg) is a real server. Refuse before any
     * trait (and so any wipe) runs unless the database is plainly a throwaway (AH-119 A0).
     * (Not beforeRefreshingDatabase(): the trait's own method would override it.)
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        if (config("database.connections.{$connection}.driver") !== 'pgsql') {
            return parent::setUpTraits();
        }

        $database = (string) config("database.connections.{$connection}.database");
        if (preg_match('/_(test|e2e)$/', $database) !== 1) {
            throw new \RuntimeException("Refusing to run migrate:fresh on Postgres database \"{$database}\": the test database must end in _test or _e2e.");
        }

        return parent::setUpTraits();
    }
}
