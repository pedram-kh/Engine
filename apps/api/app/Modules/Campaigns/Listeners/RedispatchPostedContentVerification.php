<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\PostedContentVerificationStatus;
use App\Modules\Campaigns\Jobs\VerifyPostedContentJob;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignPostedContent;
use App\Modules\Creators\Features\SocialVerificationEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Events\AccountDeletionCancelled;
use Laravel\Pennant\Feature;

/**
 * AH-104c C1-5 (Q5): while a creator is pending deletion their automatic post checks are
 * held back ({@see VerifyPostedContentJob} returns early and leaves the post `pending`).
 * When the creator cancels the request, those checks run again: the verification is
 * delayed, never lost. An erased creator never reaches here (the erasure cancels the
 * assignment).
 *
 * Only a `posted` assignment whose LATEST posted-content row is still `pending` is re-run;
 * the job itself is idempotent on `pending`, so a duplicate dispatch cannot verify twice.
 */
final class RedispatchPostedContentVerification
{
    public function handle(AccountDeletionCancelled $event): void
    {
        if (! Feature::active(SocialVerificationEnabled::NAME)) {
            return;
        }

        $creator = Creator::query()->where('user_id', $event->user->id)->first();
        if ($creator === null) {
            return;
        }

        $assignments = CampaignAssignment::query()
            ->withoutGlobalScopes()
            ->where('creator_id', $creator->id)
            ->where('status', AssignmentStatus::Posted->value)
            ->get(['id']);

        foreach ($assignments as $assignment) {
            $latest = CampaignPostedContent::query()
                ->withoutGlobalScopes()
                ->where('assignment_id', $assignment->id)
                ->orderByDesc('id')
                ->first(['ulid', 'verification_status']);

            if ($latest !== null && $latest->verification_status === PostedContentVerificationStatus::Pending) {
                VerifyPostedContentJob::dispatch($latest->ulid);
            }
        }
    }
}
