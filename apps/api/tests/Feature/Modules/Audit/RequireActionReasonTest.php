<?php

declare(strict_types=1);

use App\Modules\Audit\Http\Middleware\RequireActionReason;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    // Sample destructive route protected by the action.reason middleware.
    Route::middleware(['action.reason'])
        ->delete('/test/sample-destructive', fn () => response()->json([
            'data' => ['ok' => true],
        ]));
});

it('rejects the request with HTTP 400 and validation.reason_required when the header is missing', function (): void {
    $response = $this->deleteJson('/test/sample-destructive');

    $response->assertStatus(400);

    $payload = $response->json();

    expect($payload)->toHaveKey('errors')
        ->and($payload['errors'])->toBeArray()
        ->and($payload['errors'][0]['code'])->toBe('validation.reason_required')
        ->and($payload['errors'][0]['status'])->toBe('400')
        ->and($payload['errors'][0]['source']['header'])->toBe('X-Action-Reason')
        ->and($payload)->toHaveKey('meta.request_id');
});

it('rejects whitespace-only X-Action-Reason headers', function (): void {
    $response = $this->deleteJson('/test/sample-destructive', [], [
        'X-Action-Reason' => "   \t  ",
    ]);

    $response->assertStatus(400);
    expect($response->json('errors.0.code'))->toBe('validation.reason_required');
});

it('passes through and trims the reason when the header is present', function (): void {
    $response = $this->deleteJson('/test/sample-destructive', [], [
        'X-Action-Reason' => '   support ticket #1234   ',
    ]);

    $response->assertOk();
    $response->assertJson(['data' => ['ok' => true]]);
});

it('preserves the supplied X-Request-Id in the error envelope meta block', function (): void {
    $response = $this->deleteJson('/test/sample-destructive', [], [
        'X-Request-Id' => '01HQVKWP0M4XKMJWR5J2PXKKKQ',
    ]);

    $response->assertStatus(400);
    expect($response->json('meta.request_id'))->toBe('01HQVKWP0M4XKMJWR5J2PXKKKQ');
});

it('the action.reason middleware alias is registered with the router', function (): void {
    /** @var Router $router */
    $router = app('router');
    $aliases = $router->getMiddleware();

    expect($aliases)->toHaveKey('action.reason');
    expect($aliases['action.reason'])->toBe(RequireActionReason::class);
});
