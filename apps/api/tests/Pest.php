<?php

declare(strict_types=1);
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Note: Pest's `pest()->extend(...)->in('Feature')` global binding uses PHP
| glob() under the hood, which mis-handles literal `[` `]` characters in
| filesystem paths. Until that is fixed upstream (or the workspace path no
| longer contains brackets), each Feature test file explicitly calls
| `uses(\Tests\TestCase::class);` to bind to Laravel's testing TestCase.
|
*/

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Response macros
|--------------------------------------------------------------------------
|
| `assertEnvelopeValidationErrors(array $fields)` asserts that a 422
| response carries the canonical JSON:API error envelope from
| `docs/04-API-DESIGN.md §8` and includes at least one entry per
| expected field, addressed by `source.pointer = /data/attributes/<field>`.
|
| Replaces Laravel's built-in `assertJsonValidationErrors()` for API
| tests, which would otherwise assert the legacy `{errors:{field:[]}}`
| shape that no longer ships from the api/ app after the chunk-5
| validation-envelope normalizer landed (see
| `App\Core\Errors\ValidationExceptionRenderer`).
|
*/

TestResponse::macro(
    'assertEnvelopeValidationErrors',
    /**
     * @param  list<string>  $fields
     */
    function (array $fields): TestResponse {
        /** @var TestResponse<Response> $this */
        $this->assertStatus(422);
        $this->assertJsonStructure([
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

        $body = $this->json();
        $entries = is_array($body) && isset($body['errors']) && is_array($body['errors'])
            ? $body['errors']
            : [];

        $pointers = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $source = $entry['source'] ?? null;
            if (is_array($source) && isset($source['pointer']) && is_string($source['pointer'])) {
                $pointers[] = $source['pointer'];
            }
        }

        foreach ($fields as $field) {
            $expected = '/data/attributes/'.$field;
            Assert::assertContains(
                $expected,
                $pointers,
                sprintf(
                    'Expected validation envelope to contain pointer [%s]. Pointers seen: [%s].',
                    $expected,
                    implode(', ', $pointers),
                ),
            );
        }

        return $this;
    },
);
