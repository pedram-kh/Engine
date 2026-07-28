<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use App\Modules\Campaigns\Services\CampaignApplicationNotifier;
use App\Modules\Creators\CreatorsServiceProvider;
use Closure;

/**
 * Pennant feature flag — gates the MAIL half of the three jobs-board application
 * notifications (AH-058, D6): a creator applied (→ the agency's admins and
 * managers), and the agency's two answers, accepted / rejected (→ the creator).
 *
 * ⚠ It gates MAIL ONLY. In-app rows are written regardless, because in-app is
 * not the fan-out risk: a row lands in a feed the recipient already opens, is
 * silenced by that recipient's own `in_app` preference, and cannot be forwarded,
 * bounced or reported as spam. Mail is the leg that leaves the platform. So the
 * rule, stated once here and repeated in the review: **the flag gates mail;
 * in-app honours the recipient's own preference.**
 *
 * Same reasoning as {@see JobPostedNotificationsEnabled}, one step further out:
 * this is the arc's first mail to AGENCY users (the `submitted` verb fans to
 * every admin + manager of the agency), and the thing a flag protects is not T+0
 * but T+3, when volume nobody modelled arrives and the only question that matters
 * is how fast it stops. `Feature::deactivate` is faster than a deploy.
 *
 * ⚠ Unlike the job-posted fan-out there is NO per-run cap, and that is a
 * deliberate difference rather than an omission: volume here is bounded by human
 * action — one application, one accept, one reject at a time — with exactly one
 * exception, the campaign-terminal auto-reject (D5), whose bound is the roster
 * (a campaign cannot have more pending applications than it has rostered
 * creators, since applying requires a roster relation).
 *
 * Default state = OFF, so the loop ships DARK: the tab, the accept, the reject
 * and the auto-reject all work from deploy and write their in-app rows, and
 * nobody is emailed until an operator flips this deliberately. It joins the
 * arc's first-enable ritual alongside `job_posted_notifications_enabled` — see
 * `docs/feature-flags.md`.
 *
 * Default scope = global (Phase 1 convention). Checked INSIDE
 * {@see CampaignApplicationNotifier} — not at its call sites — so the three HTTP
 * paths and the queued auto-reject job cannot disagree, per the null-scope pin in
 * {@see CreatorsServiceProvider::configurePennantScope()}. The queued job
 * additionally re-checks on entry to `handle()`: a job enqueued before an
 * operator flipped the flag OFF must not mail on the way out.
 *
 * Lives in the CREATORS feature registry despite gating a CAMPAIGNS-module
 * service — the existing one-registry convention (`PerCampaignContractEnabled`,
 * `JobPostedNotificationsEnabled`), not an oversight.
 */
final class ApplicationNotificationsEnabled
{
    /**
     * Snake-cased registry name (docs/feature-flags.md). Every
     * `Feature::active(...)` / `Feature::activate(...)` call site MUST reference
     * this constant rather than re-typing the string.
     */
    public const NAME = 'application_notifications_enabled';

    /**
     * Default resolver. Pennant's `Feature::define(string, mixed)`
     * short-circuits when the second argument is not a {@see Closure} (it stores
     * the value as-is), so this MUST return a Closure.
     */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
