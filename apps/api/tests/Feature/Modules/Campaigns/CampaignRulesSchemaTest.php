<?php

declare(strict_types=1);

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Messaging\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-118 (campaign rules) A1 — the two additive columns, pinned before anything
 * reads them. Neither may change what an existing row means.
 */
it('adds campaigns.restrict_to_listing_regions, defaulting to OFF', function (): void {
    expect(Schema::hasColumn('campaigns', 'restrict_to_listing_regions'))->toBeTrue();

    // A campaign built without naming the field is unrestricted — today's
    // behaviour for every row that exists before the migration.
    $campaign = Campaign::factory()->createOne();
    expect($campaign->fresh()?->restrict_to_listing_regions)->toBeFalse();

    $restricted = Campaign::factory()->listed()->regionRestricted()->createOne();
    expect($restricted->fresh()?->restrict_to_listing_regions)->toBeTrue();
});

it('adds messages.system_event_data as a nullable jsonb that existing messages leave NULL', function (): void {
    expect(Schema::hasColumn('messages', 'system_event_data'))->toBeTrue();

    $plain = Message::factory()->system('assignment.contracted')->createOne();
    expect($plain->fresh()?->system_event_data)->toBeNull();

    $withData = Message::factory()
        ->system('assignment.offer_edited', ['previous_minor' => 50000, 'new_minor' => 60000, 'currency' => 'EUR'])
        ->createOne();
    expect($withData->fresh()?->system_event_data)->toBe(['previous_minor' => 50000, 'new_minor' => 60000, 'currency' => 'EUR']);
});

it('keeps both migrations schema-only: no data write, and an honest down()', function (): void {
    foreach ([
        '2026_11_05_100000_add_restrict_to_listing_regions_to_campaigns.php',
        '2026_11_05_100001_add_system_event_data_to_messages.php',
    ] as $file) {
        $source = (string) file_get_contents(database_path('migrations/'.$file));

        expect($source)->toContain('@migration-risk low')
            ->and($source)->toContain('Honest inverse')
            ->and($source)->not->toMatch('/DB::|->update\(|->insert\(|->delete\(/');
    }
});
