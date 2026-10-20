<?php

declare(strict_types=1);

use App\Modules\Creators\Models\Creator;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Erasure\Subject\SubjectSet;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ErasureGraph;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves the creator\'s own ids and nothing from another creator', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');

    $set = SubjectSet::resolve($a->user);

    expect($set->userId)->toBe($a->user->id)
        ->and($set->creatorId)->toBe($a->creator->id)
        ->and($set->userUlid)->toBe($a->user->ulid)
        ->and($set->creatorUlid)->toBe($a->creator->ulid)
        ->and($set->oldEmail)->toBe($a->email)
        ->and($set->summary())->toBe(SubjectSet::resolve($b->user)->summary());

    // Symmetric graphs: the ids differ, the shapes match, and none overlap.
    $other = SubjectSet::resolve($b->user);
    foreach (['assignmentIds', 'applicationIds', 'messageThreadIds', 'relationshipThreadIds', 'relationIds', 'taxProfileIds', 'payoutMethodIds', 'kycIds', 'socialAccountIds', 'portfolioItemIds'] as $field) {
        expect(array_intersect($set->{$field}, $other->{$field}))->toBe([], "{$field} leaked across creators");
    }
});

it('counts what the graph holds (soft-deleted rows included)', function (): void {
    $set = SubjectSet::resolve(ErasureGraph::seedSubject('a')->user);

    expect($set->summary())->toBe([
        'assignments' => 1,
        'applications' => 2,
        'message_threads' => 1,
        'relationship_threads' => 1,
        'relations' => 2,
        'blacklists' => 0,
        'tax_profiles' => 1,
        'payout_methods' => 1,
        'kyc_verifications' => 1,
        'social_accounts' => 2,
        'portfolio_items' => 2,
        'vendor_ids' => 2,
    ]);
});

it('carries the vendor ids the engine will need', function (): void {
    $graph = ErasureGraph::seedSubject('a');

    expect(SubjectSet::resolve($graph->user)->vendorIds)->toContain($graph->kycSessionId);
});

it('drops vendor ids too short to match safely against free text', function (): void {
    $user = User::factory()->creator()->createOne();
    $creator = Creator::factory()->createOne(['user_id' => $user->id]);
    DB::table('creator_kyc_verifications')->insert([
        'ulid' => (string) Str::ulid(), 'creator_id' => $creator->id, 'provider' => 'mock', 'provider_session_id' => 'abc',
        'status' => 'started', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(SubjectSet::resolve($user)->vendorIds)->toBe([]);
});

it('refuses a user who is not a creator', function (): void {
    $agencyMember = User::factory()->agencyAdmin()->createOne();

    SubjectSet::resolve($agencyMember);
})->throws(SubjectNotErasable::class, 'not a creator user');

it('refuses a creator user with no creators row', function (): void {
    $user = User::factory()->creator()->createOne();

    SubjectSet::resolve($user);
})->throws(SubjectNotErasable::class, 'no creators row');

it('refuses an already-erased subject (the old email no longer exists to resolve by)', function (): void {
    $user = User::factory()->creator()->erased()->createOne();
    Creator::factory()->createOne(['user_id' => $user->id]);

    SubjectSet::resolve($user);
})->throws(SubjectNotErasable::class, 'already erased');

it('hides the old email from var_dump and debug output', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    $set = SubjectSet::resolve($graph->user);

    ob_start();
    var_dump($set);
    $dump = (string) ob_get_clean();

    expect($dump)->not->toContain($graph->email)->and($dump)->toContain('[hidden]');
});

it('writes nothing while resolving', function (): void {
    $graph = ErasureGraph::seedSubject('a');
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (! preg_match('/^\s*select\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    SubjectSet::resolve($graph->user);

    expect($writes)->toBe([]);
});
