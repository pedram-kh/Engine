<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

/**
 * For the specs that need a SECOND database connection to see the first one's rows (row locks,
 * AH-119 A0): nothing may sit in an uncommitted wrapper transaction, so this case opts out of
 * RefreshDatabase's per-test transaction and instead rebuilds the schema around every test.
 * A migrate:fresh costs seconds, so use it for a handful of specs only. Postgres leg only.
 */
abstract class PostgresCommittedTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * No connection is wrapped in a transaction (RefreshDatabase reads this).
     *
     * @var list<string>
     */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->app['db']->connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row-lock specs need PostgreSQL (composer test:pg).');
        }

        Artisan::call('migrate:fresh');
    }

    protected function tearDown(): void
    {
        if ($this->app['db']->connection()->getDriverName() === 'pgsql') {
            // Committed rows must not leak into the next (transaction-wrapped) test.
            Artisan::call('migrate:fresh');
        }

        parent::tearDown();
    }
}
