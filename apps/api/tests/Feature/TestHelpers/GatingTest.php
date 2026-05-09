<?php

declare(strict_types=1);

use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use App\TestHelpers\TestHelpersServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

const HELPER_TOKEN_HEADER = VerifyTestHelperToken::HEADER;

// -----------------------------------------------------------------------------
// gateOpen() predicate — single source of truth for the layered gates.
// -----------------------------------------------------------------------------

it('reports gate open when env is testing and token is configured', function (): void {
    config()->set('app.env', 'testing');
    config()->set('test_helpers.token', 'pest-token');

    expect(TestHelpersServiceProvider::gateOpen())->toBeTrue();
});

it('reports gate open when env is local and token is configured', function (): void {
    config()->set('app.env', 'local');
    config()->set('test_helpers.token', 'pest-token');

    expect(TestHelpersServiceProvider::gateOpen())->toBeTrue();
});

it('reports gate closed when token is empty even in testing/local', function (): void {
    config()->set('app.env', 'testing');
    config()->set('test_helpers.token', '');

    expect(TestHelpersServiceProvider::gateOpen())->toBeFalse();
});

it('reports gate closed in production regardless of token', function (): void {
    config()->set('app.env', 'production');
    config()->set('test_helpers.token', 'leaked-token');

    expect(TestHelpersServiceProvider::gateOpen())->toBeFalse();
});

it('reports gate closed in staging regardless of token', function (): void {
    config()->set('app.env', 'staging');
    config()->set('test_helpers.token', 'leaked-token');

    expect(TestHelpersServiceProvider::gateOpen())->toBeFalse();
});

// -----------------------------------------------------------------------------
// Per-request route gate via VerifyTestHelperToken middleware.
// -----------------------------------------------------------------------------

it('returns 404 with no body when the X-Test-Helper-Token header is missing', function (): void {
    $response = $this->postJson('/api/v1/_test/clock', ['at' => '2026-05-09T00:00:00Z']);

    $response->assertStatus(404);
    expect($response->getContent())->toBe('');
});

it('returns 404 when the X-Test-Helper-Token header is wrong', function (): void {
    $response = $this->withHeader(HELPER_TOKEN_HEADER, 'wrong-token')
        ->postJson('/api/v1/_test/clock', ['at' => '2026-05-09T00:00:00Z']);

    $response->assertStatus(404);
});

it('returns 404 when the token is empty even with a header present', function (): void {
    config()->set('test_helpers.token', '');

    $response = $this->withHeader(HELPER_TOKEN_HEADER, 'anything')
        ->postJson('/api/v1/_test/clock', ['at' => '2026-05-09T00:00:00Z']);

    $response->assertStatus(404);
});

it('returns 404 when the env is not local/testing even with a correct token', function (): void {
    config()->set('app.env', 'production');

    $response = $this->withHeader(HELPER_TOKEN_HEADER, (string) config('test_helpers.token'))
        ->postJson('/api/v1/_test/clock', ['at' => '2026-05-09T00:00:00Z']);

    $response->assertStatus(404);
});

it('admits a request with the correct token in testing env', function (): void {
    $response = $this->withHeader(HELPER_TOKEN_HEADER, (string) config('test_helpers.token'))
        ->postJson('/api/v1/_test/clock/reset');

    $response->assertOk()->assertJsonPath('data.reset', true);
});

// -----------------------------------------------------------------------------
// Constant-time comparison: hash_equals defends against timing attacks.
// -----------------------------------------------------------------------------

it('uses hash_equals for token comparison (source-inspection regression)', function (): void {
    $source = (string) file_get_contents(
        base_path('app/TestHelpers/Http/Middleware/VerifyTestHelperToken.php'),
    );

    expect(str_contains($source, 'hash_equals'))->toBeTrue(
        'Token comparison must use hash_equals for timing-safety.',
    );
    expect(preg_match('/===\s*\$provided/', $source))->toBe(0,
        'Direct === comparison would reintroduce a timing oracle.',
    );
});

// -----------------------------------------------------------------------------
// Production-safety: the provider must check env before registering anything.
// -----------------------------------------------------------------------------

it('TestHelpersServiceProvider::boot() does not register routes when gate is closed', function (): void {
    // Snapshot the route table so we can compare. The act of building the
    // app already booted providers under testing+token, so the test
    // routes ARE present here. We simulate "production boot" by directly
    // calling boot() on a fresh provider instance with the gate closed
    // and asserting it registers nothing new.
    config()->set('app.env', 'production');
    config()->set('test_helpers.token', '');

    $before = count(Route::getRoutes()->getRoutes());

    $provider = new TestHelpersServiceProvider(app());
    $provider->boot();

    $after = count(Route::getRoutes()->getRoutes());

    expect($after)->toBe($before, 'Provider must not register routes when gate is closed.');
});
