<?php

declare(strict_types=1);

use App\Core\Tenancy\MissingTenancyContextException;
use App\Core\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Tenancy fail-closed contract
|--------------------------------------------------------------------------
|
| The `tenancy` middleware alias (App\Core\Tenancy\EnsureTenancyContext)
| guarantees that every tenant-scoped HTTP route either has an active
| TenancyContext or throws MissingTenancyContextException. This is the
| safety net that makes the otherwise-permissive global-scope no-op safe
| for production.
|
| The chunk-3 SetTenancyContext middleware will populate the context
| from the authenticated user's current agency BEFORE this guard runs.
| These tests prove the guard works in isolation, without taking any
| dependency on the populator.
|
| See docs/security/tenancy.md for the full contract.
|
*/

beforeEach(function (): void {
    Route::middleware(['api', 'tenancy'])->get(
        '/_test/tenant-scoped',
        static fn (): JsonResponse => response()->json(['leaked' => 'data']),
    );

    Route::middleware('api')->get(
        '/_test/cross-tenant-allowlisted',
        static fn (): JsonResponse => response()->json(['scope' => 'global']),
    );
});

afterEach(function (): void {
    app(TenancyContext::class)->forget();
});

it('fails closed when a tenant-scoped route is hit without TenancyContext', function (): void {
    $this->withoutExceptionHandling();

    try {
        $this->getJson('/_test/tenant-scoped');
        $this->fail('Expected MissingTenancyContextException, got a successful response.');
    } catch (MissingTenancyContextException $e) {
        expect($e->getMessage())
            ->toContain('requires an active TenancyContext but none was set')
            ->toContain('_test/tenant-scoped');
    }
});

it('returns HTTP 500 when the exception is rendered (default exception handler)', function (): void {
    $response = $this->getJson('/_test/tenant-scoped');

    expect($response->status())->toBe(500)
        ->and($response->getContent())->not->toContain('leaked');
});

it('passes through when TenancyContext is set before the request reaches the guard', function (): void {
    app(TenancyContext::class)->setAgencyId(42);

    $this->getJson('/_test/tenant-scoped')
        ->assertOk()
        ->assertExactJson(['leaked' => 'data']);
});

it('does not require TenancyContext on routes that omit the tenancy middleware', function (): void {
    $this->getJson('/_test/cross-tenant-allowlisted')
        ->assertOk()
        ->assertExactJson(['scope' => 'global']);
});
