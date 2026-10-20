<?php

declare(strict_types=1);

use App\Modules\Creators\Models\Contract;
use App\Modules\Erasure\Guards\VendorGuard;
use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErasureGraph;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('allows a subject whose every provider row is mock (production today)', function (): void {
    $graph = ErasureGraph::seedSubject('a');

    expect((new VendorGuard)->refusals(SubjectSet::resolve($graph->user)))->toBe([]);
});

it('refuses a real provider on any row the vendor still holds, naming the table and provider only', function (string $table, string $column, string $value, string $needle): void {
    $graph = ErasureGraph::seedSubject('a');
    ErasureGraph::seedSubject('b');
    DB::table($table)->where('creator_id', $graph->creator->id)->update([$column => $value]);

    $reasons = (new VendorGuard)->refusals(SubjectSet::resolve($graph->user));

    expect($reasons)->toHaveCount(1)
        ->and($reasons[0])->toContain($needle)->toContain($value)
        ->and($reasons[0])->not->toContain($graph->email)
        ->and($reasons[0])->not->toContain($graph->user->name);
})->with([
    'a real KYC vendor' => ['creator_kyc_verifications', 'provider', 'persona', 'creator_kyc_verifications'],
    'a live payment account' => ['creator_payout_methods', 'provider', 'stripe', 'creator_payout_methods'],
]);

it('refuses a creator-signed contract from a real e-sign vendor, but not the in-app click-through one', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    $contract = Contract::factory()->createOne(['signed_by_creator_id' => $graph->creator->id, 'signature_provider' => Contract::PROVIDER_INTERNAL]);

    expect((new VendorGuard)->refusals(SubjectSet::resolve($graph->user)))->toBe([]);

    DB::table('contracts')->where('id', $contract->id)->update(['signature_provider' => 'docusign']);

    expect((new VendorGuard)->refusals(SubjectSet::resolve($graph->user)))->toHaveCount(1);
});

it('refuses a vendor event that mentions the subject\'s vendor id', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    DB::table('integration_events')->where('provider_event_id', 'evt-a-1')->update(['provider' => 'persona']);

    $reasons = (new VendorGuard)->refusals(SubjectSet::resolve($graph->user));

    expect($reasons)->toHaveCount(1)->and($reasons[0])->toContain('integration_events')->toContain('persona');
});

it('does not read another person\'s provider rows', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');
    DB::table('creator_payout_methods')->where('creator_id', $b->creator->id)->update(['provider' => 'stripe']);

    expect((new VendorGuard)->refusals(SubjectSet::resolve($a->user)))->toBe([]);
});

it('refuses social accounts when the social driver is not mock (no provider column to read)', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    config(['integrations.social.driver' => 'instagram']);

    expect((new VendorGuard)->refusals(SubjectSet::resolve($graph->user)))->toHaveCount(1);

    config(['integrations.social.driver' => 'mock']);
    DB::table('creator_social_accounts')->where('creator_id', $graph->creator->id)->delete();
    config(['integrations.social.driver' => 'instagram']);

    expect((new VendorGuard)->refusals(SubjectSet::resolve($graph->user)))->toBe([]);
});
