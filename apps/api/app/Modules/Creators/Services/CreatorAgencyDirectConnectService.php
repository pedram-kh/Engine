<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Core\Tenancy\TenancyContext;
use App\Modules\Admin\Jobs\RunAutomationRuleJob;
use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Agencies\Mail\AdminConnectedMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\DirectConnectOutcome;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Http\Controllers\Admin\AdminCreatorConnectionController;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\ValueObjects\DirectConnectResult;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Support\Facades\DB;

/**
 * Door 2 — putting a creator on an agency's roster by fiat (AH-051 D-5, extracted
 * in AH-090 D2).
 *
 * This was `AdminCreatorConnectionController::directConnect()`. It moved because
 * AH-090 gave it a SECOND caller — a rule firing on creator approval — and a
 * queued job cannot call a method that returns a `JsonResponse`. The guard chain
 * came with it unchanged; what changed is that the decision is now a
 * {@see DirectConnectResult} the caller presents, rather than an envelope the
 * logic built for one caller.
 *
 * The two callers:
 *   - {@see AdminCreatorConnectionController::store()} with `mode=direct` — a
 *     human admin recording an offline agreement. Passes the acting admin and
 *     the admin's mandatory typed reason.
 *   - {@see RunAutomationRuleJob} — a rule firing.
 *     Passes `actor: null` (nobody clicked) and a generated reason naming the
 *     rule, plus `skipTerminalRelations: true`.
 *
 * Tenancy: the admin is not a member of the target agency, so the relation read
 * and write run inside {@see TenancyContext::runAs()} — the BelongsToAgency
 * scope and auto-fill apply as if the agency itself acted (AH-051 D-10). That
 * holds identically in a queue worker, where there is no ambient tenant at all.
 *
 * Provenance: `invited_by_user_id` takes the acting admin, or NULL when a rule
 * fired (nobody chose this agency for this creator; the audit row's rule id is
 * the provenance instead). The audit row's actor is likewise the admin or, for a
 * rule, `system` — passed EXPLICITLY rather than relying on AuditLogger's
 * auth() fallback, so the attribution cannot depend on whether the caller
 * happens to sit inside an authenticated request.
 */
final class CreatorAgencyDirectConnectService
{
    public function __construct(
        private readonly MailFactory $mail,
        private readonly NotificationService $notifications,
        private readonly TenancyContext $tenancy,
    ) {}

    /**
     * Connect `$creator` to `$agency`, or explain why not.
     *
     * Guard order is deliberate and is the order the HTTP path has always
     * applied: creator eligibility (cheap, no tenant needed) → then, inside the
     * agency's tenancy, the pair's own state (hard blacklist → already-roster →
     * the automation's terminal-state policy).
     *
     * @param  User|null  $actor  the acting admin, or null when a rule fired
     * @param  string  $reason  MANDATORY — the `admin_connected` verb refuses to
     *                          be written without one (AuditLogger throws), so
     *                          this is structural, not decorative
     * @param  bool  $skipTerminalRelations  AH-090 Q3 — when true, an `ended` or
     *                                       `declined` pair is left alone
     *                                       instead of elevated. The automation
     *                                       passes true; the human path never
     *                                       does.
     */
    public function connect(
        Creator $creator,
        Agency $agency,
        ?User $actor,
        string $reason,
        bool $skipTerminalRelations = false,
    ): DirectConnectResult {
        if ($creator->application_status !== ApplicationStatus::Approved) {
            return DirectConnectResult::refused(DirectConnectOutcome::CreatorNotApproved);
        }

        // AH-090 (D3) — a suspended agency cannot receive connections. This is a
        // DELIBERATE behaviour change to the human path, not just an automation
        // guard: before AH-090 nothing anywhere in the connect chain checked
        // suspension, so an admin could put a creator on the roster of an agency
        // whose users cannot even log in (suspension is enforced at the auth
        // layer). Keyed on `isSuspended()` / `suspended_at`, which is the SOT —
        // `is_active` is a lock-step mirror kept for backward compat, and
        // guarding the mirror would guard a copy of the truth.
        if ($agency->isSuspended()) {
            return DirectConnectResult::refused(DirectConnectOutcome::AgencySuspended);
        }

        return $this->tenancy->runAs($agency->id, function () use ($creator, $agency, $actor, $reason, $skipTerminalRelations): DirectConnectResult {
            $relation = AgencyCreatorRelation::query()
                ->where('agency_id', $agency->id)
                ->where('creator_id', $creator->id)
                ->first();

            if ($relation !== null) {
                // Hard blacklist blocks BOTH doors; soft is warn-only.
                if ($relation->is_blacklisted && $relation->blacklist_type === BlacklistType::Hard) {
                    return DirectConnectResult::refused(DirectConnectOutcome::Blacklisted, $relation);
                }

                if ($relation->relationship_status === RelationshipStatus::Roster) {
                    return DirectConnectResult::alreadyConnected($relation);
                }

                if ($skipTerminalRelations && in_array(
                    $relation->relationship_status,
                    [RelationshipStatus::Ended, RelationshipStatus::Declined],
                    true,
                )) {
                    return DirectConnectResult::refused(DirectConnectOutcome::RelationTerminal, $relation);
                }
            }

            $wasNetNew = $relation === null;

            $relation = DB::transaction(function () use ($relation, $agency, $creator, $actor, $reason): AgencyCreatorRelation {
                if ($relation === null) {
                    $relation = AgencyCreatorRelation::query()->create([
                        'agency_id' => $agency->id,
                        'creator_id' => $creator->id,
                        'relationship_status' => RelationshipStatus::Roster,
                        'invited_by_user_id' => $actor?->id,
                        'invitation_sent_at' => now(),
                        'notification_sent_at' => now(),
                    ]);
                } else {
                    // pending_request / declined / ended / prospect / external →
                    // roster. Direct-connect supersedes a pending ask.
                    $relation->relationship_status = RelationshipStatus::Roster;
                    $relation->invited_by_user_id = $actor?->id;
                    $relation->notification_sent_at = now();
                    $relation->save();
                }

                Audit::log(
                    action: AuditAction::AgencyCreatorRelationAdminConnected,
                    actor: $actor,
                    subject: $relation,
                    reason: $reason,
                    metadata: ['agency_id' => $agency->ulid, 'mode' => 'direct'],
                    agencyId: $agency->id,
                    actorType: $actor === null ? 'system' : null,
                );

                return $relation;
            });

            $this->notify($creator, $agency, $actor, $relation);

            return DirectConnectResult::connected($relation, $wasNetNew);
        });
    }

    /**
     * Dual-emit — the creator is told IMMEDIATELY (in-app + mail), naming the
     * agency, with a "contact support if unexpected" line. Guarded so a missing
     * creator user/email never fails the write that already committed.
     *
     * Identical whether an admin or a rule connected them: the in-app row's
     * actor is simply null for a rule (the resource omits it, and the SPA
     * renders the body from `type` + `data`, not from the actor), and the mail
     * names the agency, which is the part the creator cares about.
     */
    private function notify(Creator $creator, Agency $agency, ?User $actor, AgencyCreatorRelation $relation): void
    {
        $user = $creator->user;
        if ($user === null) {
            return;
        }

        $this->notifications->notify(
            recipient: $user,
            type: NotificationType::RelationAdminConnected,
            subject: $relation,
            actor: $actor,
            data: ['agency_name' => $agency->name],
        );

        if ($user->email !== '') {
            $this->mail
                ->mailer()
                ->to($user->email)
                ->locale($user->preferred_language ?: 'en')
                ->queue(new AdminConnectedMail(
                    creatorDisplayName: $creator->display_name ?? '',
                    agencyName: $agency->name,
                ));
        }
    }
}
