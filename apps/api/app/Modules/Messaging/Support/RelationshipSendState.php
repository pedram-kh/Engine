<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Services\RelationshipMessageService;
use Illuminate\Support\Facades\Gate;

/**
 * The send-state of a relationship thread, for the thread-meta payload.
 *
 * WHY THIS EXISTS (AH-051 follow-up): history on a relationship thread stays
 * readable after the relation closes — a deliberate, test-pinned posture (D6,
 * `RelationshipMessageApiTest` "still READ existing history but not SEND").
 * The SPA had no way to know that, so it rendered a live-looking composer on a
 * dead thread; the creator typed, hit send, and got a bare 403. Exposing the
 * send-state lets the client render the closed state UP FRONT instead of
 * discovering it by failing — the same "don't ship a doomed round-trip"
 * treatment the admin connect dialog got.
 *
 * ⚠ THE BOOLEAN IS NOT RE-DERIVED HERE. `can_send` delegates to the
 * `canMessageRelationship` policy, which stays the single authority for the
 * security decision — this class cannot drift from the gate because it does not
 * reimplement it. `closed_reason` is a DIAGNOSTIC only: it explains a denial
 * the policy already made, exists purely to pick user-facing copy, and is never
 * consulted for an access decision. `RelationshipMessageApiTest` pins the
 * equivalence (`closed_reason === null` if and only if `can_send === true`).
 *
 * This lives in the controller layer's vocabulary rather than
 * {@see RelationshipMessageService} on purpose:
 * that service documents itself as having NO send gate, because closure is
 * enforced at the controller via the policy (D6). Teaching it about policies
 * would contradict that boundary.
 */
final class RelationshipSendState
{
    /** The relation row is gone entirely — never connected, or hard-deleted. */
    public const REASON_NO_RELATION = 'no_relation';

    /** Severed after roster by an admin disconnect (AH-051 D6). */
    public const REASON_RELATION_ENDED = 'relation_ended';

    /** A warning overlay on the pair; blocks messaging in either direction. */
    public const REASON_BLACKLISTED = 'blacklisted';

    /** Prospect / pending_request / declined / external — never accepted. */
    public const REASON_NOT_CONNECTED = 'not_connected';

    /** The creator's application is no longer in good standing. */
    public const REASON_CREATOR_NOT_APPROVED = 'creator_not_approved';

    /** The relation qualifies but this viewer is not a party to it. */
    public const REASON_NOT_A_PARTY = 'not_a_party';

    /**
     * @return array{can_send: bool, closed_reason: string|null}
     */
    public static function for(User $viewer, Creator $creator, Agency $agency): array
    {
        $canSend = Gate::forUser($viewer)->allows('canMessageRelationship', [$creator, $agency]);

        return [
            'can_send' => $canSend,
            'closed_reason' => $canSend ? null : self::reason($creator, $agency),
        ];
    }

    /**
     * Explain a denial the policy already made.
     *
     * Precedence is ordered by what is most useful to say to a human, not by
     * how the gate evaluates: a severed relation is reported as `relation_ended`
     * even though a blacklist overlay would also block it, because "your
     * connection ended" is the fact the user needs and the lifecycle state is
     * the more specific one. The residual `not_a_party` covers a viewer who is
     * neither the creator nor an active member of this agency.
     */
    private static function reason(Creator $creator, Agency $agency): string
    {
        // The pair is resolved from already-authorized route models, so the
        // named scope bypass reads no other tenant's rows.
        $relation = AgencyCreatorRelation::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('agency_id', $agency->id)
            ->where('creator_id', $creator->id)
            ->first();

        if ($relation === null) {
            return self::REASON_NO_RELATION;
        }

        if ($relation->relationship_status === RelationshipStatus::Ended) {
            return self::REASON_RELATION_ENDED;
        }

        if ($relation->is_blacklisted) {
            return self::REASON_BLACKLISTED;
        }

        if ($relation->relationship_status !== RelationshipStatus::Roster) {
            return self::REASON_NOT_CONNECTED;
        }

        if ($creator->application_status !== ApplicationStatus::Approved) {
            return self::REASON_CREATOR_NOT_APPROVED;
        }

        return self::REASON_NOT_A_PARTY;
    }
}
