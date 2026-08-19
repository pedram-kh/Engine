<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use App\Modules\Campaigns\Listeners\SendAssignmentNotifications;
use App\Modules\Messaging\Services\DebouncedMessageMailer;
use Closure;

/**
 * Pennant feature flag — gates the MAIL legs of the two missing creator
 * emails (AH-083): ① a campaign invite (fresh invite, the AH-035 re-offer
 * after decline, and the counter-response re-offer — all three land on
 * `invited` and are treated identically, kickoff Q4) and ⑧ the debounced
 * new-message email (both thread models, `campaign` and `relationship`
 * contexts).
 *
 * ⚠ It gates MAIL ONLY. ①'s in-app row (dual-emit, kickoff Q2) is written
 * regardless — the same "flag gates mail; in-app honours the recipient's own
 * preference" rule every prior mail flag in this registry states
 * ({@see ApplicationNotificationsEnabled}, {@see JobPostedNotificationsEnabled}).
 * ⑧ has no in-app gate of its own to speak of here: the immediate in-app
 * notification (`MessageReceivedByCreator` / `MessageRelationshipReceivedByCreator`)
 * already exists and already fires unconditionally — this flag governs only
 * whether {@see DebouncedMessageMailer} is allowed to additionally queue mail.
 *
 * Checked INSIDE {@see SendAssignmentNotifications} and
 * {@see DebouncedMessageMailer} — not at their call sites — so every path that
 * can produce either verb agrees, per the null-scope pin in
 * {@see CreatorsServiceProvider::configurePennantScope()}.
 *
 * Default state = OFF: both mails ship dark. Neither is fan-out-shaped
 * (AH-083 D6) — ① is one creator per invite action (of which there are three
 * distinct paths, never more than one per action), ⑧ is capped to one email
 * per (thread, recipient) per 30 minutes by the debounce table itself — so
 * unlike the jobs-board arc's first mail there is no per-run cap to size and
 * no `--dry-run` command; the ceiling is inherent to the emission shape.
 *
 * Lives in the CREATORS feature registry despite gating CAMPAIGNS- and
 * MESSAGING-module services — the existing one-registry convention
 * ({@see PerCampaignContractEnabled}, {@see JobPostedNotificationsEnabled},
 * {@see ApplicationNotificationsEnabled}), not an oversight.
 */
final class MissingCreatorMailsEnabled
{
    /**
     * Snake-cased registry name (docs/feature-flags.md). Every
     * `Feature::active(...)` / `Feature::activate(...)` call site MUST reference
     * this constant rather than re-typing the string.
     */
    public const NAME = 'missing_creator_mails_enabled';

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
