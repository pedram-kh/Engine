<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Campaigns\Jobs\SendJobPostedNotificationsJob;
use App\Modules\Campaigns\Mail\JobPostedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignJobNotification;
use App\Modules\Campaigns\Support\JobPostedFanOutReport;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Features\JobPostedNotificationsEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

/**
 * The job-posted fan-out (AH-056, D6) — tells a rostered agency's creators that
 * a new job is on their board.
 *
 * ── Why this shape ──────────────────────────────────────────────────────────
 *
 * There is no scheduled scan, and that is not a preference. The production
 * scheduler's existence is UNVERIFIED (RESUMPTION-TEMPLATE.md's standing
 * blocker: no `supervisorctl status` / `crontab -l` has confirmed a cron entry
 * exists on the box), so a feature whose only trigger is `schedule:run` could
 * ship, pass every test, and simply never fire in production. The fan-out is
 * therefore driven by the listing flip itself: `CampaignController::update()`
 * detects a `false → true` transition and dispatches
 * {@see SendJobPostedNotificationsJob}. A queue
 * worker is verified to run; the scheduler is not.
 *
 * ── The recipient set ───────────────────────────────────────────────────────
 *
 * "Reachable roster" — the same predicate {@see JobsBoardVisibility} enforces,
 * read from the other direction. Instead of "which jobs may this creator see",
 * it asks "which creators may see this job", and the two MUST agree: a test
 * pins that every recipient this service selects has the campaign on their
 * board. That is the invariant worth protecting — a fan-out that notifies
 * someone who cannot then see the job is a broken promise, and one that skips
 * someone who can is a silent gap.
 *
 * The legs, in the same order:
 *   - the relation is roster and not blacklisted ({@see AgencyCreatorRelation::scopePermitsMessaging()});
 *   - the creator's onboarding is approved;
 *   - the creator is not HARD-blacklisted for the campaign's BRAND (C5);
 *   - the creator has a user row (there is nobody to mail otherwise);
 *   - the creator has NOT already been stamped for this campaign (D7).
 *
 * Two legs of the visibility predicate are deliberately absent because they are
 * properties of the CAMPAIGN, not the creator, and are checked once in
 * {@see self::isListable()} rather than per recipient: the listing flag with its
 * non-terminal status, and the end date.
 *
 * ── Ordering, cap, stamp ────────────────────────────────────────────────────
 *
 * Oldest-roster-first, capped at {@see self::DEFAULT_LIMIT} per run. The cap
 * bounds the blast radius of a first enable against a live roster; the
 * remainder is drained by re-running the operator command, and
 * {@see JobPostedFanOutReport::$remaining} is how the operator knows there is
 * one.
 *
 * The per-(campaign, creator) stamp is written INSIDE the loop, AFTER the two
 * emissions. That ordering is a deliberate trade (Q3): a worker retry re-runs
 * the loop and skips everyone already stamped, so the fan-out is idempotent at
 * recipient granularity and a re-list never re-notifies — at the cost of one
 * accepted failure mode, where a creator stamped whose mail then dies at the
 * transport layer is never re-notified for that job. For a fan-out to a live
 * roster, a silent single miss is the right side of the trade against a
 * double-send.
 */
final class JobPostedFanOutService
{
    /**
     * Conservative per-run cap. The platform is LIVE with ~279 creators, and
     * this is the arc's first mail fan-out to them, so one flip drains at most
     * this many — oldest-roster-first — and the operator raises it with
     * `--limit=N` once a dry-run shows the volume is safe.
     */
    public const int DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly Repository $config,
    ) {}

    /**
     * Flag-AGNOSTIC, mutation-free preview: exactly what a send at the same
     * limit would do, so the numbers an operator reads before flipping the flag
     * are the numbers they get after. Queues nothing, stamps nothing.
     */
    public function preview(Campaign $campaign, int $limit = self::DEFAULT_LIMIT): JobPostedFanOutReport
    {
        $pending = $this->pendingCount($campaign);

        return new JobPostedFanOutReport(
            notified: min($pending, max($limit, 0)),
            remaining: max($pending - max($limit, 0), 0),
        );
    }

    /**
     * Flag-GATED send. OFF is an explicit no-op — nothing queued, nothing
     * stamped — and it is the break-revert anchor for the whole outbound side.
     */
    public function send(Campaign $campaign, int $limit = self::DEFAULT_LIMIT): JobPostedFanOutReport
    {
        if (! Feature::active(JobPostedNotificationsEnabled::NAME)) {
            return JobPostedFanOutReport::disabled($this->pendingCount($campaign));
        }

        // Re-checked here rather than trusted from the dispatch site: a queued
        // job can run after the campaign was delisted again, completed, or
        // expired, and a notification for a job nobody can open is worse than
        // no notification.
        if (! $this->isListable($campaign)) {
            return new JobPostedFanOutReport(notified: 0, remaining: 0);
        }

        $notified = 0;

        foreach ($this->recipients($campaign, $limit) as $creator) {
            $user = $creator->user;

            if (! $user instanceof User) {
                continue; // unreachable: the recipient query requires a user row.
            }

            $this->emitInApp($campaign, $user);
            $this->queueMail($campaign, $user);
            $this->stamp($campaign, $creator);

            $notified++;
        }

        return new JobPostedFanOutReport(
            notified: $notified,
            remaining: $this->pendingCount($campaign),
        );
    }

    /**
     * Every eligible, un-notified recipient — uncapped. This is the honest
     * denominator behind the cap: the number an operator needs to decide
     * whether one run is enough.
     */
    public function pendingCount(Campaign $campaign): int
    {
        if (! $this->isListable($campaign)) {
            return 0;
        }

        return $this->eligibleRelations($campaign)->count();
    }

    /**
     * The capped recipient set, oldest-roster-first.
     *
     * @return Collection<int, Creator>
     */
    public function recipients(Campaign $campaign, int $limit = self::DEFAULT_LIMIT): Collection
    {
        if (! $this->isListable($campaign) || $limit < 1) {
            return new Collection;
        }

        return $this->eligibleRelations($campaign)
            ->with(['creator.user'])
            // Oldest roster relation first: the longest-standing members of the
            // roster hear about a job before this run's cap cuts the tail off.
            ->orderBy('agency_creator_relations.created_at')
            ->orderBy('agency_creator_relations.id')
            ->limit($limit)
            ->get()
            ->map(static fn (AgencyCreatorRelation $relation): ?Creator => $relation->creator)
            ->filter(static fn (?Creator $creator): bool => $creator instanceof Creator)
            ->values();
    }

    /**
     * The recipient predicate, expressed over RELATIONS rather than creators so
     * that {@see AgencyCreatorRelation::scopePermitsMessaging()} stays the one
     * source of truth for the roster leg (re-spelling roster + blacklist here is
     * exactly the drift that scope exists to prevent) and so "oldest roster
     * first" has a column to sort on.
     *
     * @return Builder<AgencyCreatorRelation>
     */
    private function eligibleRelations(Campaign $campaign): Builder
    {
        return AgencyCreatorRelation::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('agency_creator_relations.agency_id', $campaign->agency_id)
            ->permitsMessaging()
            ->whereHas('creator', function (Builder $creator): void {
                $creator->where('creators.application_status', ApplicationStatus::Approved->value)
                    // Nobody to mail or notify without a user row.
                    ->whereHas('user');
            })
            // C5 — the brand-scoped HARD blacklist, mirroring the invite gate
            // and the board predicate. Without it the fan-out would email an
            // invitation to apply that the invite gate would then hard-block.
            ->whereNotExists(function (QueryBuilder $sub) use ($campaign): void {
                $sub->from('brand_creator_blacklists')
                    ->whereColumn('brand_creator_blacklists.creator_id', 'agency_creator_relations.creator_id')
                    ->where('brand_creator_blacklists.brand_id', $campaign->brand_id)
                    ->where('brand_creator_blacklists.blacklist_type', BlacklistType::Hard->value)
                    ->whereNull('brand_creator_blacklists.deleted_at')
                    ->selectRaw('1');
            })
            // D7 — the once-only stamp. This is what makes a re-list silent.
            ->whereNotExists(function (QueryBuilder $sub) use ($campaign): void {
                $sub->from('campaign_job_notifications')
                    ->whereColumn('campaign_job_notifications.creator_id', 'agency_creator_relations.creator_id')
                    ->where('campaign_job_notifications.campaign_id', $campaign->id)
                    ->selectRaw('1');
            });
    }

    /**
     * The campaign-level half of the visibility predicate: listed, non-terminal
     * and unexpired. Evaluated against the database rather than the in-memory
     * model so a stale queued payload cannot talk the service into a send.
     */
    private function isListable(Campaign $campaign): bool
    {
        return Campaign::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->whereKey($campaign->getKey())
            ->listedOnJobsBoard()
            ->where(function (Builder $inner): void {
                $inner->whereNull('campaigns.ends_at')
                    ->orWhere('campaigns.ends_at', '>=', now('UTC')->startOfDay());
            })
            ->exists();
    }

    private function emitInApp(Campaign $campaign, User $user): void
    {
        $this->notifications->notify(
            recipient: $user,
            type: NotificationType::CampaignJobPosted,
            subject: $campaign,
            // No actor: the listing is an agency act, and attributing it to the
            // individual staff member who flipped the toggle would put an
            // agency employee's name in front of every creator on the roster.
            data: [
                'campaign_name' => $campaign->name,
                'agency_name' => $campaign->agency->name,
            ],
        );
    }

    private function queueMail(Campaign $campaign, User $user): void
    {
        Mail::to($user->email)
            ->locale($user->preferred_language ?: 'en')
            ->queue(new JobPostedMail(
                user: $user,
                campaign: $campaign,
                actionUrl: $this->jobUrl($campaign),
            ));
    }

    /**
     * Written AFTER both emissions, per recipient — see the class docblock for
     * the trade this ordering makes.
     */
    private function stamp(Campaign $campaign, Creator $creator): void
    {
        CampaignJobNotification::query()->create([
            'campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
            'notified_at' => now(),
        ]);
    }

    /** Deep link to the job detail page in the creator SPA. */
    private function jobUrl(Campaign $campaign): string
    {
        $base = rtrim((string) $this->config->get('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');

        return $base.'/creator/jobs/'.$campaign->ulid;
    }
}
