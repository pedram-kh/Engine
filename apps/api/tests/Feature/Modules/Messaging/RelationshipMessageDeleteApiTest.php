<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B2 — what is specific to deleting in the DIRECT thread: `canMessageRelationship` is NOT required
 * (history stays readable and withdrawable after the relationship closed), and the creator side is
 * structural by creator_id and agency.
 */

it('lets the creator delete after the relationship ended or the creator was blacklisted', function (): void {
    $w = revWorld();
    $s = revSurface($w, 'creator-direct');
    DB::table('agency_creator_relations')->where('agency_id', $w['agency']->id)->update(['relationship_status' => 'ended', 'is_blacklisted' => true]);

    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();
});

it('lets an agency member delete their own direct message without the send gate', function (): void {
    $w = revWorld();
    $s = revSurface($w, 'agency-direct');
    DB::table('agency_creator_relations')->where('agency_id', $w['agency']->id)->update(['is_blacklisted' => true]);

    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();
});

it('lets a creator delete only through the agency of the thread the message is in', function (): void {
    $w = revWorld();
    $s = revSurface($w, 'creator-direct');
    $other = Agency::factory()->createOne();
    $wrongUrl = str_replace($w['agency']->ulid, $other->ulid, $s['url']);

    $this->actingAs($s['actor'])->deleteJson($wrongUrl)->assertNotFound();

    expect(revRow($s['table'], $s['message']->ulid)->deleted_at)->toBeNull();
});
