<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Resources;

use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorSocialAccount;
use App\Modules\Creators\Support\PortfolioItemPresenter;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * The PUBLIC creator profile (Sprint 6.6a, D-5/D-6) — the privacy-critical
 * THIRD creator shape, distinct from the slim roster row (Chunk 5) and the
 * relation-gated detail (2a's {@see AgencyCreatorDetailResource}).
 *
 * It is the shape an agency sees for a creator it has discovered but may have
 * NO relation with (D-6: this detail does NOT 404 on no-relation — the
 * opposite of the 2a detail's relation-exists gate). So by construction it
 * carries NONE of the relation block:
 *   - NO internal_notes / internal_rating (per-agency, GDPR-sensitive)
 *   - NO blacklist facts, NO counters, NO last_engaged_at (all per-agency)
 *   - NO contact email (a relation privilege per 2a D-2a-8 — no email pre-connect)
 *   - NO admin KYC PII (kyc_method / verified_by / verifications)
 *
 * The privacy delta = "creator profile fields, minus the entire relation
 * block, minus email, minus admin KYC." Under multi-agency this is
 * load-bearing (D-7): the same creator sits on many agencies' rosters, so a
 * leak here would surface one agency's private view to another. The ONLY
 * per-agency datum is `relationship_status` — the CALLING agency's OWN status
 * (the controller's calling-agency-scoped annotation), enabling the FE's
 * "View in roster" link (D-9). NEVER another agency's status (D-7).
 *
 * It does NOT reuse `CreatorResource->withAdmin()` or the 2a resource (both
 * carry a relation block / admin KYC / email) — honest-deviation trigger #3.
 *
 * Media (D-10): the full portfolio with bounded signing (one creator, no list
 * — the roster's N+1 signing concern does not apply). Mirrors the 2a detail's
 * inline minting; the helper is duplicated (small, single consumer-shape).
 *
 * @mixin Creator
 */
final class CreatorPublicProfileResource extends JsonResource
{
    private const int SIGNED_URL_TTL_MINUTES = 60;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $creator = $this->resource;
        assert($creator instanceof Creator);

        $connectedStatus = $creator->getAttribute('connected_relationship_status');
        $connectedStatus = is_string($connectedStatus) ? $connectedStatus : null;

        return [
            'id' => $creator->ulid,
            'type' => 'creator_public_profiles',
            'attributes' => [
                'display_name' => $creator->display_name,
                'bio' => $creator->bio,
                'country_code' => $creator->country_code,
                'region' => $creator->region,
                'primary_language' => $creator->primary_language,
                'secondary_languages' => $creator->secondary_languages,
                'categories' => $creator->categories,
                'avatar_url' => $this->signedViewUrl($creator->avatar_path),
                'cover_url' => $this->signedViewUrl($creator->cover_path),
                'profile_completeness_score' => $creator->profile_completeness_score,
                'social_accounts' => $this->mapSocialAccounts($creator),
                'portfolio' => $this->mapPortfolio($creator),
                // The calling-agency-only relation status (D-4/D-9), emitted
                // RAW (Sprint 6.6b, D-5). The boolean `is_connected` was REMOVED
                // — it conflated `roster` with `pending_request`/`declined`. The
                // FE derives the status-driven send-request button + the three
                // annotation states from this alone. null ⟹ no relation.
                'relationship_status' => $connectedStatus,
            ],
        ];
    }

    /**
     * Public summary of the connected social accounts (ACCOUNTS only — the
     * follower/engagement METRICS are blocked-on-data). OAuth tokens are never
     * surfaced. Mirrors {@see AgencyCreatorDetailResource::mapSocialAccounts}.
     *
     * @return list<array<string, mixed>>
     */
    private function mapSocialAccounts(Creator $creator): array
    {
        $accounts = $creator->relationLoaded('socialAccounts')
            ? $creator->socialAccounts
            : $creator->socialAccounts()->get(['platform', 'handle', 'profile_url', 'is_primary']);

        return array_values(
            $accounts
                ->map(fn (CreatorSocialAccount $account): array => [
                    'platform' => $account->platform->value,
                    'handle' => $account->handle,
                    'profile_url' => $account->profile_url,
                    'is_primary' => $account->is_primary,
                ])
                ->all(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapPortfolio(Creator $creator): array
    {
        // AH-004: shared presenter applies the server-authoritative `ready`-gate
        // (signed URLs withheld for processing/failed items) uniformly across
        // every portfolio surface.
        return (new PortfolioItemPresenter)->mapForCreator($creator);
    }

    /**
     * Mint a presigned GET URL against the private `media` disk, or null when
     * the path is null OR the disk is non-S3 (test fakes use the local driver).
     * Mirrors {@see AgencyCreatorDetailResource::signedViewUrl}.
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
