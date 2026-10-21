<?php

declare(strict_types=1);

use App\Modules\Erasure\Registry\Disposition;
use App\Modules\Erasure\Registry\ErasureRegistry;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\CredentialRevoker;
use App\Modules\Notifications\Models\UserPushToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104b-del A2 — CredentialRevoker (kickoff D2)
|--------------------------------------------------------------------------
|
| One user's tokens, sessions and push rows go; every other user's stay. The
| coverage spec ties the list to the erasure registry.
*/

/** @return array{user: User, tokenIds: list<int>} */
function revokerSeed(string $tag, int $tokens = 2, int $sessions = 2): array
{
    $user = User::factory()->creator()->create(['email' => "revoker-{$tag}@example.test"]);
    $ids = [];

    for ($i = 0; $i < $tokens; $i++) {
        $token = $user->createToken("device-{$tag}-{$i}");
        $ids[] = $token->accessToken->id;
        UserPushToken::factory()->createOne(['user_id' => $user->id, 'personal_access_token_id' => $token->accessToken->id]);
    }

    for ($i = 0; $i < $sessions; $i++) {
        DB::table('sessions')->insert([
            'id' => "sess-{$tag}-{$i}",
            'user_id' => $user->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'phpunit',
            'payload' => 'x',
            'last_activity' => time(),
        ]);
    }

    return ['user' => $user, 'tokenIds' => $ids];
}

/** @return array{tokens: int, sessions: int, push: int} */
function revokerCounts(User $user): array
{
    return [
        'tokens' => DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count(),
        'sessions' => DB::table('sessions')->where('user_id', $user->id)->count(),
        'push' => DB::table('user_push_tokens')->where('user_id', $user->id)->count(),
    ];
}

it('revokes every token, session and push row of the user and reports the counts', function (): void {
    ['user' => $user] = revokerSeed('a');

    expect(revokerCounts($user))->toBe(['tokens' => 2, 'sessions' => 2, 'push' => 2]);

    $report = app(CredentialRevoker::class)->revokeAll($user);

    expect($report)->toBe(['push_tokens' => 2, 'tokens' => 2, 'sessions' => 2])
        ->and(revokerCounts($user))->toBe(['tokens' => 0, 'sessions' => 0, 'push' => 0]);
});

it('touches nothing of another user (over-reach negative)', function (): void {
    ['user' => $a] = revokerSeed('a');
    ['user' => $b] = revokerSeed('b', tokens: 3, sessions: 1);

    app(CredentialRevoker::class)->revokeAll($a);

    expect(revokerCounts($b))->toBe(['tokens' => 3, 'sessions' => 1, 'push' => 3]);
});

it('leaves guest sessions (no user) alone', function (): void {
    ['user' => $a] = revokerSeed('a');
    DB::table('sessions')->insert(['id' => 'guest', 'user_id' => null, 'ip_address' => '203.0.113.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);

    app(CredentialRevoker::class)->revokeAll($a);

    expect(DB::table('sessions')->where('id', 'guest')->count())->toBe(1);
});

it('is idempotent: a second call finds nothing', function (): void {
    ['user' => $user] = revokerSeed('a');
    $revoker = app(CredentialRevoker::class);

    $revoker->revokeAll($user);

    expect($revoker->revokeAll($user))->toBe(['push_tokens' => 0, 'tokens' => 0, 'sessions' => 0]);
});

it('runs the push registry delete path itself instead of leaving the rows to a cascade', function (): void {
    ['user' => $user] = revokerSeed('a', tokens: 1, sessions: 0);

    $report = app(CredentialRevoker::class)->revokeAll($user);

    expect($report['push_tokens'])->toBe(1);
});

it('does not touch password reset tokens: a pending creator may still reset their password', function (): void {
    ['user' => $user] = revokerSeed('a');
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'hash', 'created_at' => now()]);

    app(CredentialRevoker::class)->revokeAll($user);

    expect(DB::table('password_reset_tokens')->where('email', $user->email)->count())->toBe(1);
});

it('lists only tables the erasure engine erases row by row (so request and erasure cannot drift)', function (): void {
    $rules = ErasureRegistry::tables();

    foreach (CredentialRevoker::TABLES as $table) {
        expect($rules)->toHaveKey($table);
        expect($rules[$table]->disposition)->toBe(Disposition::EraseRow, "{$table} is revoked at request time but the erasure registry does not erase it");
    }
});

it('names every table it deletes from, and nothing else', function (): void {
    expect(CredentialRevoker::TABLES)->toBe(['personal_access_tokens', 'sessions', 'user_push_tokens']);

    $source = (string) file_get_contents(app_path('Modules/Identity/Services/CredentialRevoker.php'));

    preg_match_all("/table\\('([a-z_]+)'\\)/", $source, $direct);
    $touched = array_values(array_unique($direct[1]));

    expect(array_diff($touched, CredentialRevoker::TABLES))->toBe([]);
});
