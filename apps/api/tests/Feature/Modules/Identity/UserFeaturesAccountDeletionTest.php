<?php

declare(strict_types=1);

use App\Modules\Identity\Features\AccountDeletionEnabled;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * AH-104b-del B4 — `/me` carries `attributes.features.account_deletion`, the one
 * value the Security menu entry and its route read. Only a creator can ask for
 * deletion, so it is `false` for everyone else without evaluating the flag.
 */

it('is false for a creator while the flag is off, and true once it is on', function (): void {
    $creator = User::factory()->creator()->createOne();

    $this->actingAs($creator, 'web')->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.attributes.features.account_deletion', false);

    Feature::activate(AccountDeletionEnabled::NAME);

    $this->actingAs($creator, 'web')->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.attributes.features.account_deletion', true);
});

it('is false for an agency user and a platform admin even with the flag on', function (): void {
    Feature::activate(AccountDeletionEnabled::NAME);

    $agencyUser = User::factory()->agencyAdmin()->createOne();
    $this->actingAs($agencyUser, 'web')->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.attributes.features.account_deletion', false);

    $admin = User::factory()->platformAdmin()->createOne();
    $this->actingAs($admin, 'web')->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.attributes.features.account_deletion', false);
});
