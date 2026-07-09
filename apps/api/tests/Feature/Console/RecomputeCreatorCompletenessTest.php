<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** A floor-complete creator whose stored score the factory leaves at 0. */
function floorCompleteCreator(): Creator
{
    return CreatorFactory::new()->createOne([
        'display_name' => 'Recompute Me',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'creators/seed/avatar/x.jpg',
        'profile_completeness_score' => 0,
    ]);
}

it('recomputes a stale stored score to the current-formula value (D5)', function (): void {
    $creator = floorCompleteCreator();

    // What the CURRENT formula produces for this creator (computed in-process,
    // so it uses the same flag state the command will).
    $expected = app(CompletenessScoreCalculator::class)->score($creator);

    // Stamp a deliberately stale value that differs from the current score —
    // the kind of drift an old formula leaves behind on the persisted column.
    $stale = $expected === 100 ? 50 : 100;
    $creator->forceFill(['profile_completeness_score' => $stale])->save();

    $this->artisan('creators:recompute-completeness')
        ->expectsOutputToContain('1 score(s) updated')
        ->assertExitCode(0);

    expect($creator->refresh()->profile_completeness_score)->toBe($expected);
});

it('is idempotent — a second run updates nothing', function (): void {
    floorCompleteCreator();

    // First run settles the stale factory default (0) to the real score.
    $this->artisan('creators:recompute-completeness')
        ->expectsOutputToContain('1 score(s) updated')
        ->assertExitCode(0);

    // Second run: every score already matches, so nothing is written.
    $this->artisan('creators:recompute-completeness')
        ->expectsOutputToContain('0 score(s) updated')
        ->assertExitCode(0);
});

it('--dry-run reports the change but leaves the stored score untouched', function (): void {
    $creator = floorCompleteCreator();
    $expected = app(CompletenessScoreCalculator::class)->score($creator);
    $stale = $expected === 100 ? 50 : 100;
    $creator->forceFill(['profile_completeness_score' => $stale])->save();

    $this->artisan('creators:recompute-completeness --dry-run')
        ->expectsOutputToContain('1 score(s) would change')
        ->assertExitCode(0);

    expect($creator->refresh()->profile_completeness_score)->toBe($stale);
});
