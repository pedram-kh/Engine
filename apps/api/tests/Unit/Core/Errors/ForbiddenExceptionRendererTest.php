<?php

declare(strict_types=1);

use App\Core\Errors\ForbiddenExceptionRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

it('emits a canonical envelope entry for a gate denial', function (): void {
    // The shape `prepareException()` hands to the render callbacks for a
    // `Gate::authorize()` failure.
    $exception = new AccessDeniedHttpException('This action is unauthorized.');
    $request = Request::create('/test', 'POST');

    $response = ForbiddenExceptionRenderer::render($exception, $request);

    expect($response->getStatusCode())->toBe(403);

    $body = json_decode((string) $response->getContent(), true);

    expect($body)->toHaveKeys(['errors', 'meta']);
    expect($body['errors'])->toBeArray()->toHaveCount(1);
    expect($body['meta']['request_id'])->toBeString();

    $entry = $body['errors'][0];
    expect($entry)->toHaveKeys(['id', 'status', 'code', 'title']);
    expect($entry['status'])->toBe('403');
    expect($entry['code'])->toBe(ForbiddenExceptionRenderer::CODE);
    // A deliberate policy message survives into the envelope.
    expect($entry['title'])->toBe('This action is unauthorized.');
});

it('falls back to the canonical sentence when the exception carries no message', function (): void {
    // The bare `abort(403)` case — an empty message must never render as a
    // blank title in the SPA.
    $exception = new HttpException(403, '');
    $request = Request::create('/test', 'GET');

    $response = ForbiddenExceptionRenderer::render($exception, $request);

    $body = json_decode((string) $response->getContent(), true);

    $title = (string) $body['errors'][0]['title'];
    expect($title)->toBe(ForbiddenExceptionRenderer::FALLBACK_TITLE);
    expect($title)->not->toBe('');
});

it('is parseable by the SPA envelope contract (an errors[] array with a code)', function (): void {
    // The regression this renderer exists to prevent: `ApiError.fromEnvelope`
    // rejects any body without a non-empty `errors[]` and falls back to
    // `Unrecognized error response (HTTP 403).`
    $response = ForbiddenExceptionRenderer::render(
        new AccessDeniedHttpException('This action is unauthorized.'),
        Request::create('/test', 'POST'),
    );

    $body = json_decode((string) $response->getContent(), true);

    expect($body['errors'])->toBeArray();
    expect($body['errors'])->not->toBeEmpty();

    $code = (string) $body['errors'][0]['code'];
    $title = (string) $body['errors'][0]['title'];
    expect($code)->not->toBe('');
    expect($title)->not->toBe('');
});
