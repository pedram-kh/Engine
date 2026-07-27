<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use App\Modules\Campaigns\Services\JobPostedFanOutService;
use App\Modules\Creators\CreatorsServiceProvider;
use Closure;

/**
 * Pennant feature flag — gates the jobs-board job-posted fan-out (AH-056, D6):
 * the in-app + email notification sent to a rostered agency's creators when
 * that agency lists a campaign on the jobs board.
 *
 * ⚠ Deliberately non-standard. The house rule is that features ship
 * unconditionally and a flag has to earn its keep; this one earns it on the
 * OUTBOUND side, not the correctness side. It is the arc's first mail fan-out
 * to LIVE creators (~279 on the platform today), and the thing a flag protects
 * is not T+0 — where the code is fresh in mind and one campaign is listed — but
 * T+3, when a bulk listing or an unnoticed loop produces a send nobody intended
 * and the only question that matters is how fast it stops. `Feature::deactivate`
 * is faster than a deploy. Same reasoning, same registry, same shape as
 * {@see IncompleteCreatorNudgeEnabled}.
 *
 * Default state = OFF, so the fan-out ships DARK: the board itself works from
 * deploy, and nobody is emailed until an operator has previewed volume with
 * `campaigns:preview-job-notifications {campaign} --dry-run` and flipped the
 * flag deliberately. The first-enable ritual is written up in
 * `docs/feature-flags.md`.
 *
 * Default scope = global (Phase 1 convention). The flag is checked INSIDE
 * {@see JobPostedFanOutService::send()} — not in the queued job's caller and
 * not in the command — so the HTTP flip path, the console drain and the admin
 * toggle all agree via the null-scope pin in
 * {@see CreatorsServiceProvider::configurePennantScope()}.
 *
 * Lives in the CREATORS feature registry despite gating a CAMPAIGNS-module
 * service. That is the existing one-registry convention, not an oversight:
 * `PerCampaignContractEnabled` and `ContractSigningEnabled` already gate
 * campaign/contract behaviour from here. Splitting the registry per module is a
 * separate decision and this is not the change that should start it (Q5).
 */
final class JobPostedNotificationsEnabled
{
    /**
     * Snake-cased registry name (docs/feature-flags.md). Every
     * `Feature::active(...)` / `Feature::activate(...)` call site MUST reference
     * this constant rather than re-typing the string.
     */
    public const NAME = 'job_posted_notifications_enabled';

    /**
     * Default resolver. Pennant's `Feature::define(string, mixed)`
     * short-circuits when the second argument is not a {@see Closure} (it
     * stores the value as-is), so this MUST return a Closure.
     */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
