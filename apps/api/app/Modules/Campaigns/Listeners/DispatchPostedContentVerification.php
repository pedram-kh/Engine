<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Jobs\VerifyPostedContentJob;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Features\SocialVerificationEnabled;
use Laravel\Pennant\Feature;

/**
 * The 2nd consumer of {@see AssignmentTransitioned} (Sprint 9 Chunk 2, D-10),
 * after {@see CreateAssignmentAvailabilityBlock}. Dispatches the
 * {@see VerifyPostedContentJob} when a creator self-reports a post
 * (`assignment.posted_by_creator`) — NOT inline in Chunk 1's posted endpoint
 * (keeps Chunk 1 untouched; the event-consumer pattern).
 *
 * Flag-gated (D-11): when `social_verification_enabled` is OFF, no job is
 * dispatched — the post stays `verification_status=pending` and the assignment
 * stays `posted` (production-without-adapter safe; the verifyLive transition
 * would refuse anyway). The job re-checks the flag as defense-in-depth.
 *
 * The `posted_content_id` rides the transition's context (threaded by
 * {@see CampaignAssignmentStateMachine::markPosted()}
 * in Chunk 1).
 */
final class DispatchPostedContentVerification
{
    public function handle(AssignmentTransitioned $event): void
    {
        if ($event->action !== AuditAction::AssignmentPostedByCreator) {
            return;
        }

        if (! Feature::active(SocialVerificationEnabled::NAME)) {
            return;
        }

        $postedContentUlid = $event->context['posted_content_id'] ?? null;
        if (! is_string($postedContentUlid) || $postedContentUlid === '') {
            return;
        }

        VerifyPostedContentJob::dispatch($postedContentUlid);
    }
}
