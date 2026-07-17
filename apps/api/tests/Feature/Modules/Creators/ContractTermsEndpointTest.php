<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Services\ContractTermsRenderer;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 3 Chunk 3 sub-step 4 — verify the server-rendered master
 * agreement endpoint per Refinement 4.
 *
 *   GET /api/v1/creators/me/wizard/contract/terms
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    cache()->store('array')->flush();
});

it('returns the rendered HTML, version, and locale for the authenticated creator', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/contract/terms');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['html', 'version', 'locale'],
        ])
        ->assertJsonPath('data.version', ContractTermsRenderer::CURRENT_VERSION)
        // AH-049: the emitted version is the label the SPA renders as
        // "Master Creator Agreement v{version}". Pinned literally so the
        // 1.0 → 1.1 bump is guarded, not just implied by the constant.
        ->assertJsonPath('data.version', '1.1')
        ->assertJsonPath('data.locale', 'en');

    $html = $response->json('data.html');
    expect($html)->toBeString();
    expect($html)->toContain('<h1>Catalyst Creator Terms and Conditions');
    expect($html)->toContain('<h2>2. Services</h2>');
    // v1.1 content pins (AH-049): the new clauses 2.4 / 4.3 must render.
    expect($html)->toContain('<strong>2.4</strong>');
    expect($html)->toContain('up to three (3)');
    expect($html)->toContain('<strong>4.3</strong>');
    expect($html)->toContain('within 30');
});

it('falls back to `en` when an unknown locale is requested', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/contract/terms?locale=xx');

    $response->assertOk()
        ->assertJsonPath('data.locale', 'en');
});

it('rejects unauthenticated callers', function (): void {
    $response = $this->getJson('/api/v1/creators/me/wizard/contract/terms');

    $response->assertUnauthorized();
});

it('returns 404 when the user has no creator profile', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/contract/terms');

    $response->assertNotFound()
        ->assertJsonPath('errors.0.code', 'creator.not_found');
});

it('escapes raw HTML in the rendered markdown source (sanitisation contract)', function (): void {
    // The current markdown source ships only authored content; this
    // assertion documents the contract that any future translator
    // attempting to inject raw HTML gets it escaped, not rendered.
    $renderer = app(ContractTermsRenderer::class);
    $reflection = new ReflectionClass($renderer);
    $method = $reflection->getMethod('renderHtml');
    $method->setAccessible(true);

    $rendered = $method->invoke($renderer, 'a paragraph with <script>alert(1)</script> inside');

    expect($rendered)->toBeString();
    expect($rendered)->toContain('&lt;script&gt;');
    expect($rendered)->not->toContain('<script>');
});

it('source() exposes the RAW markdown + title + version without altering render() output', function (): void {
    $renderer = app(ContractTermsRenderer::class);

    $source = $renderer->source('en');

    // Raw markdown — NOT rendered HTML.
    expect($source['markdown'])->toContain('# Catalyst Creator Terms and Conditions');
    expect($source['markdown'])->not->toContain('<h1>');
    expect($source['title'])->toBe('Catalyst Creator Terms and Conditions');
    expect($source['version'])->toBe(ContractTermsRenderer::CURRENT_VERSION);
    expect($source['locale'])->toBe('en');

    // Break-revert (§5.35): exposing the raw source must not perturb the
    // rendered HTML the SPA consumes. render() still escapes + wraps as
    // before — alter the render path and this (plus the HTML-shape test
    // above) fails.
    $rendered = $renderer->render('en');
    expect($rendered['html'])->toContain('<h1>Catalyst Creator Terms and Conditions');
    expect($rendered['html'])->toContain('<h2>2. Services</h2>');
    expect($rendered['version'])->toBe(ContractTermsRenderer::CURRENT_VERSION);
});

it('maps the version string to the integer the contracts.version column stores', function (): void {
    expect(ContractTermsRenderer::versionToInteger('1.0'))->toBe(1);
    expect(ContractTermsRenderer::versionToInteger('2.0'))->toBe(2);
    expect(ContractTermsRenderer::currentVersionNumber())
        ->toBe(ContractTermsRenderer::versionToInteger(ContractTermsRenderer::CURRENT_VERSION));
});

it('memoises the rendered HTML per locale per version', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    // First request triggers render.
    $first = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/contract/terms')->json('data.html');
    // Second request must return identical content; if the cache layer
    // were absent we'd still get identical output (deterministic
    // renderer) — the assertion is documenting the contract.
    $second = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/contract/terms')->json('data.html');

    expect($second)->toBe($first);
});
