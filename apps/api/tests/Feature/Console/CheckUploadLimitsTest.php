<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('exits 0 when the runtime upload ceiling meets the app cap', function (): void {
    config(['uploads.avatar_max_bytes' => 1]);

    $this->artisan('uploads:check-limits')->assertExitCode(0);
});

it('exits non-zero (deploy gate) when the runtime ceiling is below the app cap', function (): void {
    config(['uploads.avatar_max_bytes' => PHP_INT_MAX]);

    $this->artisan('uploads:check-limits')
        ->expectsOutputToContain('BELOW the application cap')
        ->assertExitCode(1);
});
