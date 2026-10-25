<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

use App\Modules\Creators\Exceptions\CreatorUnavailableException;
use App\Modules\Creators\Services\CreatorAgencyDirectConnectService;

/**
 * What {@see CreatorAgencyDirectConnectService::connect()} decided (AH-090, D2).
 *
 * ONE vocabulary, two presentations. The admin HTTP endpoint maps a case to a
 * status + wire code; the automation job maps the SAME case to a structured-log
 * outcome token. Before this enum existed the two lists were the same knowledge
 * spelled twice — the AH-051 `connection.*` codes inside the controller and the
 * AH-090 `skipped:*` reasons inside the job — which is exactly how a new refusal
 * ships with an HTTP code and no log line, or vice versa. Adding a case here
 * forces both presentations to be written.
 *
 * `Connected` is the only case that wrote a relation. Every other case is a
 * refusal or a no-op, and every one of them is a legible skip reason for the
 * automation — silence is never an outcome (§5.35, the AH-058 logEmission lesson).
 */
enum DirectConnectOutcome: string
{
    /** A `roster` relation was created or elevated into existence. */
    case Connected = 'connected';

    /** The pair was already on the roster — nothing written, nothing emitted. */
    case AlreadyConnected = 'already_connected';

    /** `approved` binds both doors (AH-051, D-4) and the automation alike. */
    case CreatorNotApproved = 'creator_not_approved';

    /** A HARD agency-wide blacklist blocks the connect (soft is warn-only). */
    case Blacklisted = 'blacklisted';

    /** AH-090 D3 — the target agency is suspended; its users cannot even log in. */
    case AgencySuspended = 'agency_suspended';

    /**
     * AH-090 D3/Q3, AUTOMATION-ONLY — the pair sits in a deliberately terminal
     * state (`ended`: an admin severed it with a mandatory reason and a
     * dual-emit; `declined`: the creator refused this agency). A human choosing
     * Door 2 still elevates those, because a human choosing IS the consent the
     * elevation encodes. A rule must never reverse either decision. Unreachable
     * through HTTP today — the controller never asks for the terminal policy.
     */
    case RelationTerminal = 'relation_terminal';

    /**
     * AH-104c C2-4a — the creator is leaving: erased, a deletion request stands, or an erasure is
     * claimed (`Creator::isUnavailableToAgencies()`). No connection, by hand or by a rule, is made
     * to a person who is about to disappear; the agency would be told of a creator who is gone.
     * Asked FIRST, before approval: an agency-neutral answer, the same code every agency-side door
     * gives (`creator.unavailable`).
     */
    case CreatorUnavailable = 'creator_unavailable';

    public function isConnected(): bool
    {
        return $this === self::Connected;
    }

    /**
     * The HTTP status the admin Door-2 endpoint answers with.
     *
     * `Connected` reads 200 here; the controller promotes it to 201 when the
     * relation was net-new, because "created" is a property of the write, not
     * of the outcome (an elevated relation is a 200 that also connected).
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Connected, self::AlreadyConnected => 200,
            default => 422,
        };
    }

    /**
     * The AH-051 wire code — `meta.code` on a success, `errors.0.code` on a
     * refusal. Unchanged strings: the admin SPA and AdminCreatorConnectionTest
     * both read these, so the extraction moved where they are produced, never
     * what they say. `direct_blacklisted` keeps its mode distinction (Door 1
     * answers `request_blacklisted` from its own guard, which stayed inline).
     */
    public function apiCode(): string
    {
        return match ($this) {
            self::Connected => 'connection.direct_connected',
            self::AlreadyConnected => 'connection.already_connected',
            self::CreatorNotApproved => 'connection.creator_not_approved',
            self::Blacklisted => 'connection.direct_blacklisted',
            self::AgencySuspended => 'connection.agency_suspended',
            self::RelationTerminal => 'connection.relation_terminal',
            self::CreatorUnavailable => CreatorUnavailableException::CODE,
        };
    }

    /**
     * The developer-facing `detail` line. English by the existing AH-051
     * posture — clients render from `code`, not from this.
     */
    public function detail(): string
    {
        return match ($this) {
            self::Connected => 'The creator is connected to the agency.',
            self::AlreadyConnected => 'This creator is already on that agency’s roster.',
            self::CreatorNotApproved => 'This creator is not approved and cannot be connected.',
            self::Blacklisted => 'This creator is hard-blacklisted for the selected agency.',
            self::AgencySuspended => 'This agency is suspended and cannot receive connections.',
            self::RelationTerminal => 'This relationship was deliberately ended or declined and is not re-created automatically.',
            self::CreatorUnavailable => 'This creator is leaving the platform and cannot be connected.',
        };
    }

    /**
     * The token the automation writes into its one structured line per fire.
     * `connected` or `skipped:<reason>` — never absent, never bare.
     */
    public function logOutcome(): string
    {
        return $this === self::Connected ? 'connected' : 'skipped:'.$this->value;
    }
}
