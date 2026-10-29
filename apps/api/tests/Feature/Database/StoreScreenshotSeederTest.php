<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Notifications\Models\Notification;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\StoreScreenshotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// AH-105 S5 — the screenshot seeder is fiction, dev-only, and never part of the default seed.

it('refuses to run outside local/testing', function (): void {
    app()['env'] = 'production';

    expect(fn () => (new StoreScreenshotSeeder)->run())
        ->toThrow(RuntimeException::class, 'only allowed in local and testing');

    // Nothing was written before the guard tripped.
    expect(User::query()->where('email', StoreScreenshotSeeder::CREATOR_EMAIL)->exists())->toBeFalse();
    expect(Agency::query()->count())->toBe(0);
});

it('refuses in staging too', function (): void {
    app()['env'] = 'staging';

    expect(fn () => (new StoreScreenshotSeeder)->run())->toThrow(RuntimeException::class);
});

it('is not called by DatabaseSeeder', function (): void {
    $file = (new ReflectionClass(DatabaseSeeder::class))->getFileName();
    expect($file)->toBeString();
    $source = (string) file_get_contents((string) $file);

    expect($source)->not->toContain('StoreScreenshotSeeder');
});

it('seeds the screenshot dataset: one approved creator, one agency, 4 jobs, 3 invitations, threads, 8 notifications', function (): void {
    Storage::fake('media');

    $this->seed(StoreScreenshotSeeder::class);

    $creator = Creator::query()
        ->whereHas('user', fn ($q) => $q->where('email', StoreScreenshotSeeder::CREATOR_EMAIL))
        ->firstOrFail();

    expect($creator->application_status)->toBe(ApplicationStatus::Approved)
        ->and($creator->avatar_path)->not->toBeNull()
        ->and(CreatorPortfolioItem::query()->where('creator_id', $creator->id)->count())->toBe(4)
        ->and($creator->socialAccounts()->count())->toBe(2);

    // Strength is below 100 (the master contract is unsigned) and is the calculator's own number.
    $score = app(CompletenessScoreCalculator::class)->score($creator);
    expect($creator->profile_completeness_score)->toBe($score)
        ->and($score)->toBeLessThan(100)
        ->and($score)->toBeGreaterThanOrEqual(50);

    $agency = Agency::query()->where('slug', 'studio-lumora-demo')->firstOrFail();
    expect(AgencyCreatorRelation::query()->where('agency_id', $agency->id)->where('creator_id', $creator->id)->count())->toBe(1);

    // Four campaigns on the jobs board, and the three invitations are NOT among them.
    expect(Campaign::query()->withoutGlobalScopes()->listedOnJobsBoard()->count())->toBe(4);

    $statuses = CampaignAssignment::query()
        ->withoutGlobalScopes()
        ->where('creator_id', $creator->id)
        ->pluck('status')
        ->map(fn ($s) => $s->value)
        ->sort()
        ->values()
        ->all();
    expect($statuses)->toBe([
        AssignmentStatus::Contracted->value,
        AssignmentStatus::Countered->value,
        AssignmentStatus::Invited->value,
    ]);

    expect(RelationshipMessage::query()->count())->toBe(6)
        ->and(Message::query()->count())->toBe(4)
        ->and(Notification::query()->where('recipient_user_id', $creator->user_id)->count())->toBe(8);

    // Every file the rows point at was written to the (faked) media disk.
    expect($creator->avatar_path)->toBeString();
    Storage::disk('media')->assertExists((string) $creator->avatar_path);
    CreatorPortfolioItem::query()->where('creator_id', $creator->id)->each(
        fn (CreatorPortfolioItem $item) => Storage::disk('media')->assertExists((string) $item->s3_path),
    );
});

it('is idempotent: a second run changes nothing', function (): void {
    Storage::fake('media');

    $this->seed(StoreScreenshotSeeder::class);
    $before = [Agency::query()->count(), User::query()->count(), Notification::query()->count()];

    $this->seed(StoreScreenshotSeeder::class);

    expect([Agency::query()->count(), User::query()->count(), Notification::query()->count()])->toBe($before);
});
