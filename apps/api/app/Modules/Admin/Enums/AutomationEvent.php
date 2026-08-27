<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Creators\Http\Controllers\Admin\AdminCreatorController;

/**
 * The platform events a rule can watch (AH-090, D1).
 *
 * One case in v1. The value is the same verb string the audit log uses for the
 * transition (`creator.approved` = {@see AuditAction::CreatorApproved}),
 * following the `board_automations.event_key` precedent — an operator reading a
 * rule and an operator reading the audit log see the same word for the same
 * thing.
 *
 * Adding a case is a code change with no migration: the column is a plain
 * varchar and this enum is the vocabulary. What a new case DOES need is a hook —
 * something at the site of the transition that queries enabled rules and
 * dispatches. Nothing fires by magic.
 *
 * Stored as varchar(64) on `admin_automation_rules.event`.
 */
enum AutomationEvent: string
{
    /**
     * A platform admin approved a creator's application — written in exactly one
     * place, {@see AdminCreatorController::approve()},
     * which is where the hook lives. Re-approval after a reopen fires again; the
     * connect operation's idempotency absorbs it.
     */
    case CreatorApproved = 'creator.approved';

    /** The i18n key suffix / SPA select label key for this event. */
    public function labelKey(): string
    {
        return match ($this) {
            self::CreatorApproved => 'creatorApproved',
        };
    }
}
