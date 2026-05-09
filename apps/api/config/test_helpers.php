<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Helpers
|--------------------------------------------------------------------------
|
| Configuration for the App\TestHelpers module. The module exposes a
| handful of `/api/v1/_test/*` endpoints used by the Playwright E2E suite
| (chunk 6 priority #19, #20) so specs can drive the SPA without
| reading mailboxes or burning real wall-clock time.
|
| Production safety is layered:
|   1. App\TestHelpers\TestHelpersServiceProvider only registers anything
|      when app()->environment() is `local` or `testing`. In production
|      the routes do not exist on the route table at all.
|   2. Even within local/testing, every route is gated by the
|      `App\TestHelpers\Http\Middleware\VerifyTestHelperToken` middleware,
|      which compares the `X-Test-Helper-Token` header against the value
|      below using hash_equals. A missing or wrong header returns a bare
|      404 — indistinguishable from any other unknown route, so the gate
|      cannot be probed.
|   3. The `App\TestHelpers\Http\Middleware\ApplyTestClock` middleware
|      mounts as a global no-op when the token is unset; it only reads
|      the Redis-backed test clock when the same env+token gate is open.
|
| Token rotation:
|   - Local dev: the .env.example default is fine; the host is your own
|     laptop. Override per-developer if you like.
|   - CI: generate a fresh value per run (e.g.
|     `TEST_HELPERS_TOKEN=$(openssl rand -hex 32)`) and inject it into
|     BOTH the API container env AND the Playwright runner env. Never
|     check a CI value into source control or ship a known token to a
|     deployed environment.
|
| See `app/TestHelpers/README.md` for the full operator runbook.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Shared-secret token
    |--------------------------------------------------------------------------
    |
    | Required for every request to the test-helpers endpoints. An empty
    | string disables the helpers entirely (the routes still exist when
    | the env is local/testing but every request 404s). Production has no
    | routes registered at all, regardless of this value.
    |
    */
    'token' => (string) env('TEST_HELPERS_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Cache key for the simulated clock
    |--------------------------------------------------------------------------
    |
    | The `ApplyTestClock` middleware reads this key on every request; the
    | `SetClockController` writes it; `ResetClockController` deletes it.
    | The key lives in the application cache (Redis in dev/staging/prod,
    | array driver in tests). The "test:" prefix is so a stray operator
    | on the production Redis can immediately see this is a debug knob.
    |
    */
    'clock_cache_key' => 'test:clock:current',
];
