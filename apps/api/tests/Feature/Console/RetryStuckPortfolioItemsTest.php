<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Database\Factories\CreatorPortfolioItemFactory;
use App\Modules\Creators\Enums\PortfolioProcessingStatus;
use App\Modules\Creators\Jobs\ProcessPortfolioImageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * `portfolio:retry-stuck` — the recovery path for items stranded at
 * `processing` by an uncatchable worker kill (July 2026 incident).
 */
beforeEach(function (): void {
    Bus::fake([ProcessPortfolioImageJob::class]);
});

it('re-dispatches the job for an image stuck at processing past the age threshold', function (): void {
    $creator = CreatorFactory::new()->createOne();

    $this->travelTo(now()->subHour());
    $stuck = CreatorPortfolioItemFactory::new()->processing()->createOne([
        'creator_id' => $creator->id,
    ]);
    $this->travelBack();

    $this->artisan('portfolio:retry-stuck')
        ->expectsOutputToContain('1 item(s) re-dispatched')
        ->assertExitCode(0);

    Bus::assertDispatched(
        ProcessPortfolioImageJob::class,
        fn (ProcessPortfolioImageJob $job): bool => $job->portfolioItemId === $stuck->id,
    );
});

it('skips a recent processing item (still racing the in-flight job) and non-processing items', function (): void {
    $creator = CreatorFactory::new()->createOne();

    // Fresh `processing` (inside the default 10-minute window), `ready`, and
    // `failed` (without --include-failed) must all be left alone.
    CreatorPortfolioItemFactory::new()->processing()->createOne(['creator_id' => $creator->id]);
    CreatorPortfolioItemFactory::new()->createOne(['creator_id' => $creator->id]);
    CreatorPortfolioItemFactory::new()->failed()->createOne(['creator_id' => $creator->id]);

    $this->artisan('portfolio:retry-stuck')
        ->expectsOutputToContain('No stuck portfolio items found')
        ->assertExitCode(0);

    Bus::assertNotDispatched(ProcessPortfolioImageJob::class);
});

it('--include-failed resets a failed item to processing and re-dispatches it', function (): void {
    $creator = CreatorFactory::new()->createOne();
    $failed = CreatorPortfolioItemFactory::new()->failed()->createOne([
        'creator_id' => $creator->id,
    ]);

    $this->artisan('portfolio:retry-stuck --include-failed')
        ->expectsOutputToContain('1 item(s) re-dispatched')
        ->assertExitCode(0);

    expect($failed->refresh()->processing_status)->toBe(PortfolioProcessingStatus::Processing);
    Bus::assertDispatched(
        ProcessPortfolioImageJob::class,
        fn (ProcessPortfolioImageJob $job): bool => $job->portfolioItemId === $failed->id,
    );
});

it('--dry-run lists the stuck item but dispatches nothing and writes nothing', function (): void {
    $creator = CreatorFactory::new()->createOne();

    $this->travelTo(now()->subHour());
    $stuck = CreatorPortfolioItemFactory::new()->processing()->createOne([
        'creator_id' => $creator->id,
    ]);
    CreatorPortfolioItemFactory::new()->failed()->createOne(['creator_id' => $creator->id]);
    $this->travelBack();

    $this->artisan('portfolio:retry-stuck --dry-run --include-failed')
        ->expectsOutputToContain('2 item(s) would be re-dispatched')
        ->assertExitCode(0);

    expect($stuck->refresh()->processing_status)->toBe(PortfolioProcessingStatus::Processing);
    Bus::assertNotDispatched(ProcessPortfolioImageJob::class);
});
