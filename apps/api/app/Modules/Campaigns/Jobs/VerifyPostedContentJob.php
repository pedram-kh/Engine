<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Enums\PostedContentVerificationStatus;
use App\Modules\Campaigns\Listeners\DispatchPostedContentVerification;
use App\Modules\Campaigns\Mail\PostVerificationFailedMail;
use App\Modules\Campaigns\Models\CampaignPostedContent;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Features\SocialVerificationEnabled;
use App\Modules\Creators\Integrations\Contracts\SocialPlatformProvider;
use App\Modules\Creators\Integrations\Enums\PostVerificationOutcome;
use App\Modules\Creators\Jobs\SimulateEsignWebhookJob;
use App\Modules\Creators\Models\CreatorSocialAccount;
use App\Modules\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

/**
 * Verify a creator's self-reported post against the social provider (Sprint 9
 * Chunk 2, D-10) — the arc-closer. Mirrors {@see SimulateEsignWebhookJob}
 * (a ShouldQueue job resolving an integration provider out of the container).
 *
 * Dispatched by {@see DispatchPostedContentVerification}
 * on `assignment.posted_by_creator` (NOT inline in Chunk 1's posted endpoint).
 *
 * The job:
 *   - calls {@see SocialPlatformProvider::verifyPostUrl()} (mock today) keyed on
 *     the creator's connected handle for the post's platform + the post URL;
 *   - writes the outcome onto `campaign_posted_content.verification_status` +
 *     stamps `verified_at` / `platform_post_id` on a verified match;
 *   - on `verified` → drives the machine `posted → live_verified` (D-12 — the
 *     state really advances; the FE labels it "simulated");
 *   - on `not_found` / `mismatch` → leaves the assignment `posted` (D-13, NO
 *     machine call) + notifies the agency (D-14).
 *
 * Flag-gated (D-11): a no-op when `social_verification_enabled` is OFF — the
 * dispatch listener already gates, this is defense-in-depth so a job queued
 * while the flag was ON never calls a disabled provider after a flip.
 */
final class VerifyPostedContentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $postedContentUlid,
    ) {}

    public function handle(
        SocialPlatformProvider $provider,
        CampaignAssignmentStateMachine $machine,
    ): void {
        if (! Feature::active(SocialVerificationEnabled::NAME)) {
            return;
        }

        $posted = CampaignPostedContent::query()
            ->where('ulid', $this->postedContentUlid)
            ->with(['assignment.creator.user', 'assignment.campaign', 'assignment.invitedBy'])
            ->first();

        if ($posted === null) {
            return;
        }

        // Idempotent — only an as-yet-unverified post is processed (no
        // re-verification on a retry / re-dispatch, no auto-retry on failure).
        if ($posted->verification_status !== PostedContentVerificationStatus::Pending) {
            return;
        }

        $assignment = $posted->assignment;
        if ($assignment === null) {
            return;
        }

        $handle = $this->connectedHandle($assignment->creator_id, $posted->platform);

        $result = $provider->verifyPostUrl($handle, $posted->post_url);
        $status = PostedContentVerificationStatus::from($result->outcome->value);

        $posted->verification_status = $status;
        if ($result->outcome === PostVerificationOutcome::Verified) {
            $posted->verified_at = now();
            $posted->platform_post_id = $result->platformPostId;
        }
        $posted->save();

        if ($result->outcome === PostVerificationOutcome::Verified) {
            // The state really advances (Sprint 10's payment trigger now
            // exists). verifyLive double-checks the flag itself.
            $machine->verifyLive($assignment, null, context: [
                'posted_content_id' => $posted->ulid,
                'platform_post_id' => $posted->platform_post_id,
            ]);

            return;
        }

        // not_found / mismatch — stay posted, notify the agency (D-13/D-14).
        $this->notifyAgencyOfFailure($posted, $result->outcome);
    }

    private function connectedHandle(int $creatorId, string $platform): string
    {
        $handle = CreatorSocialAccount::query()
            ->where('creator_id', $creatorId)
            ->where('platform', $platform)
            ->orderByDesc('is_primary')
            ->value('handle');

        return is_string($handle) ? $handle : '';
    }

    private function notifyAgencyOfFailure(CampaignPostedContent $posted, PostVerificationOutcome $outcome): void
    {
        $assignment = $posted->assignment;
        $recipient = $assignment?->invitedBy;
        $campaign = $assignment?->campaign;
        $creator = $assignment?->creator;

        if (! $recipient instanceof User || $recipient->email === '' || $campaign === null || $creator === null) {
            return;
        }

        // Narrow to the two failure literals the mail accepts (Verified never
        // reaches this path — it returns earlier in handle()).
        $reason = $outcome === PostVerificationOutcome::Mismatch ? 'mismatch' : 'not_found';

        Mail::to($recipient->email)
            ->locale($recipient->preferred_language ?: 'en')
            ->queue(new PostVerificationFailedMail(
                recipientName: $recipient->name,
                creatorName: $creator->display_name ?? '',
                campaignName: $campaign->name,
                outcome: $reason,
                campaignUlid: $campaign->ulid,
            ));
    }
}
