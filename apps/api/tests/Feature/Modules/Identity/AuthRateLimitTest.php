<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('limits the login endpoint to 5 attempts per minute per email', function (): void {
    User::factory()->createOne(['email' => 'rl@example.com']);

    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'rl@example.com',
            'password' => 'wrong-password-9999',
        ]);
    }

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'rl@example.com',
        'password' => 'wrong-password-9999',
    ]);

    $response->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'rate_limit.exceeded');
});

it('limits the forgot-password endpoint to 5 requests per minute per IP', function (): void {
    User::factory()->createOne(['email' => 'rl-pw@example.com']);

    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'rl-pw@example.com']);
    }

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'rl-pw@example.com'])
        ->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'rate_limit.exceeded');
});

it('emits a Retry-After header on 429 responses', function (): void {
    User::factory()->createOne(['email' => 'retry@example.com']);

    for ($i = 1; $i <= 6; $i++) {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'retry@example.com',
            'password' => 'wrong-password-9999',
        ]);
        if ($response->getStatusCode() === 429) {
            expect($response->headers->has('Retry-After'))->toBeTrue();

            return;
        }
    }
    $this->fail('expected at least one 429 response within 6 attempts');
});
