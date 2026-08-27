<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

use App\Modules\Admin\Jobs\RunAutomationRuleJob;
use App\Modules\Creators\Services\CreatorAgencyDirectConnectService;

/**
 * What a rule DOES when its event fires (AH-090, D1).
 *
 * One case in v1. Each action owns the shape of `admin_automation_rules.config`
 * — which is why the reference to a target agency lives in that JSON rather than
 * in a column: the next action may target a talent pool or nothing at all.
 *
 * Adding a case needs no migration; it needs a handler in
 * {@see RunAutomationRuleJob} and validation for its own
 * config shape.
 */
enum AutomationAction: string
{
    /**
     * Put the creator on a named agency's roster — the same operation a human
     * admin performs through Door 2, fired by a rule
     * ({@see CreatorAgencyDirectConnectService}).
     *
     * `config`: `{"agency_id": "<agency ULID>"}`.
     */
    case ConnectToAgency = 'connect_to_agency';

    /** The i18n key suffix / SPA select label key for this action. */
    public function labelKey(): string
    {
        return match ($this) {
            self::ConnectToAgency => 'connectToAgency',
        };
    }

    /**
     * The `config` keys this action requires. Used by the CRUD validation so a
     * rule cannot be stored with a config its own action cannot read.
     *
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return match ($this) {
            self::ConnectToAgency => ['agency_id'],
        };
    }
}
