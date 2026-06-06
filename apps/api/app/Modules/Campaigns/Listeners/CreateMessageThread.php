<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Messaging\Services\MessageThreadService;

/**
 * The 4th consumer of {@see AssignmentTransitioned} (Sprint 11, D-3).
 *
 * When a creator is invited to a campaign, provision the assignment's message
 * thread so both parties have somewhere to talk from day one. This is a REAL
 * listener — the "listener-not-inline" precedent set by
 * {@see SendAssignmentNotifications} — registered after the existing three in
 * CampaignsServiceProvider (alongside the other AssignmentTransitioned
 * consumers, the established home for this event's listeners).
 *
 * Idempotent: the create is delegated to {@see MessageThreadService::forAssignment()}
 * (firstOrCreate keyed on the `assignment_id` UNIQUE), so a re-invite or a
 * thread that already exists (e.g. lazily created on a GET) is a no-op. The lazy
 * GET create heals any thread-less assignment that predates this listener — so
 * no backfill migration is needed (D-3).
 */
final class CreateMessageThread
{
    public function __construct(private readonly MessageThreadService $threads) {}

    public function handle(AssignmentTransitioned $event): void
    {
        if ($event->action !== AuditAction::AssignmentInvited) {
            return;
        }

        $this->threads->forAssignment($event->assignment);
    }
}
