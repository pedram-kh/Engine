<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Push\PushTokenRegistry;
use Illuminate\Support\Facades\DB;

/**
 * AH-104b-del (kickoff D2) — everything that signs a person in, revoked at once.
 *
 * Called when a creator requests deletion: from that moment no device and no browser
 * of theirs stays signed in. The set is ONE list ({@see self::TABLES}), and a spec
 * asserts the erasure engine erases every table on it, so a credential surface added
 * here at request time cannot be forgotten at erasure (and the reverse is pinned by
 * the registry spec's own classification of the same tables).
 *
 * What it does NOT revoke, on purpose:
 *   - password reset tokens: a pending creator may still reset their password (the
 *     mail goes to the owner), and a reset does not cancel the request;
 *   - the audit trail and push delivery telemetry: those are records, not credentials.
 *
 * The write is scoped to ONE user id at every statement (a bystander's rows are never
 * touched; §5.34), and it is idempotent: a second call finds nothing and returns zeros.
 * The caller owns the transaction.
 */
final class CredentialRevoker
{
    /**
     * Every table whose rows are a live way to be signed in as the user. The erasure
     * registry must classify each as ERASE-ROW (asserted in CredentialRevokerTest).
     *
     * @var list<string>
     */
    public const TABLES = ['personal_access_tokens', 'sessions', 'user_push_tokens'];

    public function __construct(private readonly PushTokenRegistry $pushTokens) {}

    /**
     * @return array{push_tokens: int, tokens: int, sessions: int}
     */
    public function revokeAll(User $user): array
    {
        // Push rows first and explicitly: deleting the bearer tokens cascades to them in
        // the database, which would leave this count at zero and hide whether the
        // registry's own delete path ran.
        $pushTokens = $this->pushTokens->revokeAllForUser($user);

        $tokens = $user->tokens()->delete();

        $sessions = DB::table('sessions')->where('user_id', $user->getKey())->delete();

        return ['push_tokens' => $pushTokens, 'tokens' => (int) $tokens, 'sessions' => $sessions];
    }
}
