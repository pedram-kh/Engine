<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Messaging\Services\MessageThreadService;

/**
 * Writes a system message into the assignment's thread on curated lifecycle
 * transitions (Sprint 11, D-4) — the "listener-not-inline" precedent, a 5th
 * consumer of {@see AssignmentTransitioned} registered in CampaignsServiceProvider.
 *
 * Gated by {@see self::SYSTEM_MESSAGE_TRANSITIONS}: lifecycle milestones only,
 * NOT field churn. The thread is provisioned defensively first (D-3 site b), so
 * a system message can land even on an assignment that never opened a thread.
 * The `system_event_key` is the AuditAction verb string; the body is rendered
 * client-side / in the digest from that key + the thread context — never stored
 * text (D-5).
 *
 * Terminal-state milestones (e.g. `payment_released`) still write here: a system
 * message IS the closing event (D-13). Only HUMAN sends are terminal-guarded.
 */
final class WriteSystemMessage
{
    /**
     * The curated lifecycle allowlist (D-4). Anything outside this set — field
     * edits, re-invites, payment_funded, producing, etc. — writes no system
     * message.
     *
     * @var array<int, AuditAction>
     */
    public const array SYSTEM_MESSAGE_TRANSITIONS = [
        AuditAction::AssignmentContracted,
        AuditAction::AssignmentDraftSubmitted,
        AuditAction::AssignmentDraftApproved,
        AuditAction::AssignmentRevisionRequested,
        AuditAction::AssignmentDraftRejected,
        AuditAction::AssignmentPostedByCreator,
        AuditAction::AssignmentLiveVerified,
        AuditAction::AssignmentManuallyVerified,
        AuditAction::AssignmentResubmitRequested,
        AuditAction::AssignmentPaymentReleased,
    ];

    public function __construct(
        private readonly MessageThreadService $threads,
        private readonly MessageService $messages,
    ) {}

    public function handle(AssignmentTransitioned $event): void
    {
        if (! in_array($event->action, self::SYSTEM_MESSAGE_TRANSITIONS, true)) {
            return;
        }

        $thread = $this->threads->forAssignment($event->assignment);
        $this->messages->writeSystemMessage($thread, $event->action->value);
    }
}
