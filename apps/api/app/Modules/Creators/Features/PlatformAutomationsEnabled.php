<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use App\Modules\Admin\Jobs\RunAutomationRuleJob;
use Closure;

/**
 * Pennant feature flag — the KILL SWITCH for platform automations (AH-090, C8).
 *
 * Gates whether a configured rule is allowed to ACT. Nothing else: rules can be
 * created, edited, enabled and listed with the flag off — they simply never fire.
 *
 * Why a flag when each rule already has `enabled`: those are different tools.
 * Per-rule `enabled` is CONFIGURATION — an operator deciding this particular
 * rule should not run. The flag is the kill switch — one flip that stops every
 * rule, including ones an operator has not thought about, which is what you want
 * at the moment you discover the connect path is misbehaving. Five rules must be
 * able to die with one flip, not five row edits performed while they fire.
 *
 * Checked INSIDE {@see RunAutomationRuleJob::handle()},
 * not at the dispatch site — the AH-058 posture. Minutes can pass between an
 * approval and the worker picking the job up, and the answer that matters is the
 * one at fire time: a flag turned off during those minutes must stop the fire,
 * and a job already queued must not sneak through.
 *
 * Default state = OFF. This is the platform's first automated WRITE path into
 * live relationship data, so it ships dark exactly like every prior
 * first-of-its-kind emission ({@see JobPostedNotificationsEnabled},
 * {@see ApplicationNotificationsEnabled}, {@see MissingCreatorMailsEnabled}) —
 * with the stronger reason that this one writes rows rather than sending mail.
 *
 * Lives in the CREATORS feature registry despite gating an ADMIN-module job —
 * the existing one-registry convention, not an oversight (see
 * {@see MissingCreatorMailsEnabled}, which gates Campaigns and Messaging
 * services from here).
 */
final class PlatformAutomationsEnabled
{
    /**
     * Snake-cased registry name (docs/feature-flags.md). Every
     * `Feature::active(...)` / `Feature::activate(...)` call site MUST reference
     * this constant rather than re-typing the string.
     */
    public const NAME = 'platform_automations_enabled';

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
