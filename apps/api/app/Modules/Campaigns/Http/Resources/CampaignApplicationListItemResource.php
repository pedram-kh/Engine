<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Resources;

use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Policies\CreatorPolicy;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * One ROW in the campaign-detail Applications tab (AH-058, D1).
 *
 * ── The identity question, answered ─────────────────────────────────────────
 *
 * Every applicant is ROSTERED with this agency by definition: leg 3 of the
 * jobs-board visibility predicate is `permitsMessaging()` (roster, not
 * blacklisted), so a creator who cannot see the job cannot apply to it. The
 * fields below — display name, avatar, the creator ULID for the profile link —
 * are therefore data the agency can already read on its own roster page, and
 * this resource creates **NO new exposure**. That is why there is no new gate
 * here beyond the campaign's `view` ability.
 *
 * Contact details are NOT emitted, and that is not a restriction this resource
 * invents: {@see CreatorPolicy::canSeeContactDetails()} (AH-051) is backed by the
 * same `permitsMessaging()` primitive, so the agency may see the applicant's
 * email — on the roster surface, where that policy is applied. A list row does
 * not need it, and re-deriving the gate here would be a second copy of a
 * predicate that already has one home.
 *
 * The `note` IS emitted: it is free text the creator wrote FOR this surface.
 *
 * ⚠ Exact-keyset assertion. This shape is pinned key-for-key by its spec (the
 * chunk-3 accretion guard, reused): a field added here without a decision fails
 * the test rather than quietly widening what an agency reads about an applicant.
 *
 * @mixin CampaignApplication
 */
final class CampaignApplicationListItemResource extends JsonResource
{
    private const int SIGNED_URL_TTL_MINUTES = 60;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CampaignApplication $application */
        $application = $this->resource;

        $creator = $application->relationLoaded('creator') ? $application->creator : null;

        return [
            'id' => $application->ulid,
            'type' => 'campaign_application_list_item',
            'attributes' => [
                'status' => $application->status->value,
                'note' => $application->note,
                'applied_at' => $application->created_at->toIso8601String(),
                // Null while pending; the stamp of the agency's (or the
                // campaign-closure's) answer once terminal.
                'responded_at' => $application->responded_at?->toIso8601String(),
                'creator' => $creator instanceof Creator ? [
                    'id' => $creator->ulid,
                    'display_name' => $creator->display_name,
                    'avatar_url' => $this->signedViewUrl($creator->avatar_path),
                ] : null,
            ],
        ];
    }

    /**
     * Mint a presigned GET URL against the private `media` disk, or null when the
     * path is null OR the disk is non-S3 (test fakes use the local driver, which
     * throws on temporaryUrl). The {@see CreatorDiscoveryResource} shape.
     *
     * Signing is bounded by the page size (25 rows), the same trade the
     * discovery grid makes.
     */
    private function signedViewUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('media');

        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        return $disk->temporaryUrl($path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
    }
}
