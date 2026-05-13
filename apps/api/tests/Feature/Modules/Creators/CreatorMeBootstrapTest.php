<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\WizardStep;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('GET /api/v1/creators/me returns the bootstrap shape for the authenticated user', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me');

    $response->assertOk()
        ->assertJsonPath('data.id', $creator->ulid)
        ->assertJsonPath('data.type', 'creators')
        ->assertJsonPath('data.wizard.next_step', WizardStep::Profile->value)
        ->assertJsonPath('data.wizard.is_submitted', false)
        ->assertJsonStructure([
            'data' => [
                'id', 'type',
                'attributes' => ['display_name', 'application_status', 'profile_completeness_score'],
                'wizard' => ['next_step', 'is_submitted', 'steps', 'weights'],
            ],
        ]);
});

it('GET /api/v1/creators/me returns 404 when the user has no creator row', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me');

    $response->assertNotFound()
        ->assertJsonPath('errors.0.code', 'creator.not_found');
});

it('GET /api/v1/creators/me requires authentication', function (): void {
    $response = $this->getJson('/api/v1/creators/me');

    $response->assertUnauthorized();
});

it('the bootstrap response keys per-step status by string identifier (Q2)', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me');

    $steps = $response->json('data.wizard.steps');
    expect($steps)->toBeArray();

    foreach ($steps as $step) {
        expect($step)->toHaveKeys(['id', 'is_complete']);
        expect($step['id'])->toBeString();
        // The id must be a known WizardStep::value (not a numeric index).
        expect(WizardStep::tryFrom($step['id']))->not->toBeNull();
    }
});
