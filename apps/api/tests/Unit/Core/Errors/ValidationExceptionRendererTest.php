<?php

declare(strict_types=1);

use App\Core\Errors\ValidationExceptionRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('emits one envelope entry per (field, message) pair with /data/attributes pointers', function (): void {
    $validator = Validator::make([], [
        'name' => ['required'],
        'slug' => ['required'],
    ]);
    expect($validator->fails())->toBeTrue();

    $exception = new ValidationException($validator);
    $request = Request::create('/test', 'POST');

    $response = ValidationExceptionRenderer::render($exception, $request);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode((string) $response->getContent(), true);

    expect($body)->toHaveKeys(['errors', 'meta']);
    expect($body['errors'])->toBeArray()->toHaveCount(2);
    expect($body['meta']['request_id'])->toBeString();
    expect(strlen($body['meta']['request_id']))->toBeGreaterThan(0);

    $pointers = array_map(
        static fn (array $entry): string => $entry['source']['pointer'],
        $body['errors'],
    );
    expect($pointers)->toContain('/data/attributes/name', '/data/attributes/slug');

    foreach ($body['errors'] as $entry) {
        expect($entry)->toHaveKeys(['id', 'status', 'code', 'title', 'detail', 'source', 'meta']);
        expect($entry['status'])->toBe('422');
        expect($entry['code'])->toBe(ValidationExceptionRenderer::CODE);
        expect($entry['detail'])->toBe($entry['title']);
        expect($entry['meta']['field'])->toBeIn(['name', 'slug']);
        expect($entry['meta']['rule'])->toBe('Required');
    }
});

it('emits multiple entries when a single field has multiple violations', function (): void {
    $validator = Validator::make(['slug' => 'BAD slug!'], [
        'slug' => ['required', 'regex:/^[a-z0-9-]+$/', 'max:3'],
    ]);
    expect($validator->fails())->toBeTrue();

    $exception = new ValidationException($validator);
    $request = Request::create('/test', 'POST');

    $response = ValidationExceptionRenderer::render($exception, $request);
    $body = json_decode((string) $response->getContent(), true);

    $slugEntries = array_values(array_filter(
        $body['errors'],
        static fn (array $e): bool => ($e['meta']['field'] ?? null) === 'slug',
    ));

    expect(count($slugEntries))->toBeGreaterThanOrEqual(2);
    $slugPointers = array_values(array_unique(array_map(
        static fn (array $entry): string => $entry['source']['pointer'],
        $slugEntries,
    )));
    expect($slugPointers)->toBe(['/data/attributes/slug']);
});

it('passes through a pre-built response from FormRequest::failedValidation() without re-wrapping', function (): void {
    // FormRequest::failedValidation() may attach a fully-formed JsonResponse
    // to the ValidationException (see AdminUpdateCreatorRequest for the
    // canonical example). The renderer must NOT regenerate a generic
    // `validation.failed` envelope over that bespoke response — doing so
    // would erase domain-specific error codes the caller carefully wired.
    $validator = Validator::make(
        ['application_status' => 'approved'],
        ['application_status' => ['prohibited']],
    );
    expect($validator->fails())->toBeTrue();

    $preBuilt = new JsonResponse([
        'errors' => [[
            'status' => '422',
            'code' => 'creator.admin.field_status_immutable',
            'title' => 'Status transitions use approve / reject endpoints.',
            'source' => ['pointer' => '/data/attributes/application_status'],
        ]],
    ], 422);

    $exception = new ValidationException($validator, $preBuilt);
    $request = Request::create('/test', 'PATCH');

    $response = ValidationExceptionRenderer::render($exception, $request);

    expect($response)->toBe($preBuilt);
    $body = json_decode((string) $response->getContent(), true);
    expect($body['errors'][0]['code'])->toBe('creator.admin.field_status_immutable');
});

it('honors an inbound X-Request-Id header on the envelope meta', function (): void {
    $validator = Validator::make([], ['email' => ['required']]);
    expect($validator->fails())->toBeTrue();

    $exception = new ValidationException($validator);
    $request = Request::create('/test', 'POST');
    $request->headers->set('X-Request-Id', '01HQTEST00000000000000000');

    $response = ValidationExceptionRenderer::render($exception, $request);
    $body = json_decode((string) $response->getContent(), true);

    expect($body['meta']['request_id'])->toBe('01HQTEST00000000000000000');
});

it('renders the envelope end-to-end on a real JSON API route (validation pipeline integration)', function (): void {
    // Hit a public route that uses a FormRequest so we exercise the registered
    // global renderer in bootstrap/app.php — not just the renderer in isolation.
    // /api/v1/auth/sign-up rejects an empty payload with a validation 422; we
    // only care that the envelope shape is emitted, not the specific fields.
    $response = $this->postJson('/api/v1/auth/sign-up', []);

    $response->assertStatus(422);
    $response->assertJsonStructure([
        'errors' => [
            [
                'id',
                'status',
                'code',
                'title',
                'detail',
                'source' => ['pointer'],
                'meta' => ['field'],
            ],
        ],
        'meta' => ['request_id'],
    ]);
    $response->assertJsonPath('errors.0.code', ValidationExceptionRenderer::CODE);
    $response->assertJsonPath('errors.0.status', '422');
});
