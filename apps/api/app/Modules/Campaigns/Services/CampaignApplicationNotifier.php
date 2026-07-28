<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Enums\ApplicationRejectionCause;
use App\Modules\Campaigns\Mail\ApplicationAcceptedMail;
use App\Modules\Campaigns\Mail\ApplicationRejectedMail;
use App\Modules\Campaigns\Mail\ApplicationSubmittedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Creators\Features\ApplicationNotificationsEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

/**
 * The three jobs-board application notifications, in one place (AH-058, D6).
 *
 * ── Why one service ─────────────────────────────────────────────────────────
 *
 * Four emit sites (apply, accept, reject, campaign-terminal auto-reject) and
 * three verbs. Each emission is a PAIR — an in-app row plus a queued localized
 * mail — and AH-051's lesson is that a pair split across call sites drifts: one
 * site gains a leg the other never gets. Both legs of each verb are emitted from
 * one method here, so a site cannot half-emit.
 *
 * ── ⚠ CALL THESE AFTER YOUR TRANSACTION COMMITS ─────────────────────────────
 *
 * `config/queue.php` sets `after_commit => false` on every connection, so
 * `Mail::queue()` inside an open transaction is visible to a worker
 * IMMEDIATELY — before the commit, and regardless of whether the commit ever
 * happens. An accept whose transaction later rolls back would have already told
 * the creator they were accepted for an assignment that does not exist.
 *
 * So every caller wraps its DB writes in one transaction and calls in AFTER it
 * returns, which is the `CampaignController::update()` post-save dispatch
 * pattern. The residual failure mode inverts to the strictly better one: a
 * committed accept whose in-app row failed to write (the truth is in the
 * database, the notice is missing) rather than a notice for a truth that was
 * rolled back.
 *
 * ── The flag ────────────────────────────────────────────────────────────────
 *
 * `application_notifications_enabled` (default OFF) is checked HERE, once per
 * emission, and it gates the MAIL leg only: **the flag gates mail; in-app
 * honours the recipient's own preference** (a recipient who has switched the
 * type's `in_app` toggle off gets no row either way — that read lives in
 * {@see NotificationService::notify()}). Checking inside the service rather than
 * at the call sites is what keeps the three HTTP paths and the queued auto-reject
 * job in agreement.
 *
 * ── Tenancy ─────────────────────────────────────────────────────────────────
 *
 * Reads here drop {@see BelongsToAgencyScope} explicitly and take their agency
 * from the application row's own denormalized `agency_id`. The auto-reject job
 * calls in from a worker with NO ambient tenant, so a scoped read would come back
 * null and the notification would silently not send.
 */
final class CampaignApplicationNotifier
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly Repository $config,
    ) {}

    /**
     * A creator applied → the agency's admins + managers (D6).
     *
     * Staff are excluded by {@see Agency::notifiableMembers()}'s load-bearing
     * exclusion, which means an agency staff member who CAN invite is not told
     * when someone applies. Pre-existing asymmetry across every agency-facing
     * notification, recorded in the chunk's review rather than fixed here.
     */
    public function submitted(CampaignApplication $application): void
    {
        $campaign = $this->campaign($application);
        $creator = $application->creator;
        $agency = $this->agency($application);

        if (! $campaign instanceof Campaign || ! $creator instanceof Creator || ! $agency instanceof Agency) {
            return;
        }

        $creatorName = $this->creatorName($creator);
        $actionUrl = $this->campaignUrl($campaign);

        foreach ($agency->notifiableMembers() as $member) {
            $this->notifications->notify(
                recipient: $member,
                type: NotificationType::CampaignApplicationSubmitted,
                subject: $application,
                // No actor: the applicant is a CREATOR, and `actor_user_id` is a
                // users-table key. The creator's name travels in the data bag.
                data: [
                    'creator_name' => $creatorName,
                    'campaign_name' => $campaign->name,
                ],
            );

            if ($member->email === '') {
                continue;
            }

            $this->queue($member, new ApplicationSubmittedMail(
                recipientName: $member->name,
                creatorName: $creatorName,
                campaignName: $campaign->name,
                actionUrl: $actionUrl,
            ));
        }
    }

    /**
     * An application was accepted → the creator (D6).
     *
     * `$assignmentUlid` is the invitation the accept created, so the notice links
     * to the offer that now needs an answer rather than back to the job page. It
     * is required, not optional: an accepted application without an assignment is
     * not a state this system produces (both are written in one transaction).
     */
    public function accepted(CampaignApplication $application, string $assignmentUlid): void
    {
        $campaign = $this->campaign($application);
        $creator = $application->creator;
        $agency = $this->agency($application);

        if (! $campaign instanceof Campaign || ! $creator instanceof Creator || ! $agency instanceof Agency) {
            return;
        }

        $recipient = $creator->user;

        if (! $recipient instanceof User) {
            return;
        }

        $this->notifications->notify(
            recipient: $recipient,
            type: NotificationType::CampaignApplicationAccepted,
            subject: $application,
            // No actor for the same reason the job-posted fan-out has none: the
            // accept is an agency act, and naming the individual employee who
            // pressed it puts their name in front of the creator.
            data: [
                'agency_name' => $agency->name,
                'campaign_name' => $campaign->name,
                'assignment_ulid' => $assignmentUlid,
            ],
        );

        if ($recipient->email === '') {
            return;
        }

        $this->queue($recipient, new ApplicationAcceptedMail(
            creatorName: $this->creatorName($creator, $recipient),
            agencyName: $agency->name,
            campaignName: $campaign->name,
            actionUrl: $this->assignmentUrl($assignmentUlid),
        ));
    }

    /**
     * An application was rejected → the creator (D4/D5).
     *
     * One verb, two causes: the agency's answer and the campaign-terminal
     * auto-reject. `data.cause` is part of the contract — the SPA template may
     * read it and the mailable's blade appends it to `body_` to pick the variant.
     */
    public function rejected(CampaignApplication $application, ApplicationRejectionCause $cause): void
    {
        $campaign = $this->campaign($application);
        $creator = $application->creator;

        if (! $campaign instanceof Campaign || ! $creator instanceof Creator) {
            return;
        }

        $recipient = $creator->user;

        if (! $recipient instanceof User) {
            return;
        }

        $this->notifications->notify(
            recipient: $recipient,
            type: NotificationType::CampaignApplicationRejected,
            subject: $application,
            data: [
                'campaign_name' => $campaign->name,
                'cause' => $cause->value,
            ],
        );

        if ($recipient->email === '') {
            return;
        }

        $this->queue($recipient, new ApplicationRejectedMail(
            creatorName: $this->creatorName($creator, $recipient),
            campaignName: $campaign->name,
            cause: $cause,
            // The jobs board, not the closed job: the answer is final either
            // way, so the useful next step is the next job.
            actionUrl: $this->jobsBoardUrl(),
        ));
    }

    /**
     * The ONE flag check and the ONE queue-time locale application. Every mail
     * leg goes through here so neither can be forgotten at a call site.
     */
    private function queue(User $recipient, ApplicationSubmittedMail|ApplicationAcceptedMail|ApplicationRejectedMail $mailable): void
    {
        if (! Feature::active(ApplicationNotificationsEnabled::NAME)) {
            return;
        }

        Mail::to($recipient->email)
            // Queue-time locale, not render-time: the worker has no request
            // locale, so a mailable resolving its own would send everyone English.
            ->locale($recipient->preferred_language ?: 'en')
            ->queue($mailable);
    }

    /**
     * The campaign, read WITHOUT the tenancy scope.
     *
     * The auto-reject job runs in a worker with no ambient tenant, where a scoped
     * `$application->campaign` would resolve to null and the notification would
     * silently not send. The agency is not inferred from context either — it is
     * matched against the application's own denormalized `agency_id`, so the
     * unscoped read cannot reach across tenants.
     */
    private function campaign(CampaignApplication $application): ?Campaign
    {
        $campaign = $application->relationLoaded('campaign') ? $application->campaign : null;

        if ($campaign instanceof Campaign) {
            return $campaign;
        }

        return Campaign::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('campaigns.agency_id', $application->agency_id)
            ->whereKey($application->campaign_id)
            ->first();
    }

    private function agency(CampaignApplication $application): ?Agency
    {
        $agency = $application->relationLoaded('agency') ? $application->agency : null;

        if ($agency instanceof Agency) {
            return $agency;
        }

        return Agency::query()->whereKey($application->agency_id)->first();
    }

    /**
     * The creator's display name, falling back to the user's name — the
     * `notifyCreatorOfReview` shape.
     */
    private function creatorName(Creator $creator, ?User $user = null): string
    {
        $user ??= $creator->user;

        return $creator->display_name ?? ($user instanceof User ? $user->name : '');
    }

    /**
     * The agency-side campaign page. Deliberately NOT tab-addressed: the detail
     * page's tab is local component state, not a route parameter, so a
     * `?tab=applications` link would be a promise the SPA does not keep. Same
     * shape as every other campaign mail.
     */
    private function campaignUrl(Campaign $campaign): string
    {
        return $this->frontendBase().'/campaigns/'.$campaign->ulid;
    }

    /** The creator-side assignment detail — where the new offer is answered. */
    private function assignmentUrl(string $assignmentUlid): string
    {
        return $this->frontendBase().'/creator/assignments/'.$assignmentUlid;
    }

    /** The creator-side jobs board. */
    private function jobsBoardUrl(): string
    {
        return $this->frontendBase().'/creator/jobs';
    }

    private function frontendBase(): string
    {
        return rtrim((string) $this->config->get('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');
    }
}
