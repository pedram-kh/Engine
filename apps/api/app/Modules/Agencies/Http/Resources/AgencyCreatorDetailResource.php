<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Resources;

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use App\Modules\Creators\Models\CreatorSocialAccount;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Agency-side per-creator DETAIL view (Sprint 6 Chunk 2a, D-2a-2).
 *
 * A dedicated resource — NOT {@see \App\Modules\Creators\Http\Resources\CreatorResource}
 * with `withAdmin(true)`. That factory exposes admin-only KYC attribution
 * (method / verifier / verification history) that must NEVER reach an agency;
 * and the agency-private relation data (rating / notes / blacklist status /
 * counters) has no home on it. So this composes, in one shape:
 *
 *   - the RELATION block (the agency's private view of the creator): rating,
 *     notes, the read-only blacklist STATUS, and the denormalized counters;
 *   - the nested creator PROFILE: display fields + signed avatar/cover URLs,
 *     the contact email (D-2a-8 — a deliberate privacy decision: the agency
 *     holds a verified relation with this creator, so the contact email is
 *     appropriate here; the slim roster LIST now also surfaces it, eager-loaded
 *     to avoid the N+1 that originally kept it off the list), social accounts,
 *     and portfolio.
 *
 * What this resource DELIBERATELY DOES NOT carry:
 *   - admin-only KYC PII (kyc_method, verified_by_user_id, kyc_verifications)
 *     — break-revert anchor: the no-admin-PII assertion;
 *   - `blacklist_reason` — free-text GDPR-sensitive (the same data class as
 *     `internal_notes`, which D-2a-5 redacts from the audit log). Surfacing
 *     the justification text while redacting notes would be incoherent, so
 *     only the STRUCTURED blacklist facts (flag / scope / type / date) ship.
 *     Blacklist EDITING is Sprint 7 (display read-only here).
 *
 * Signed URLs are minted inline (one creator, no list — the roster's N+1
 * signing concern does not apply; honest-deviation trigger #1 stays clear).
 * The ~minting helper mirrors {@see CreatorResource::signedViewUrl()};
 * duplicated rather than extracted (single small helper, one consumer-shape).
 *
 * @mixin AgencyCreatorRelation
 */
final class AgencyCreatorDetailResource extends JsonResource
{
    /** TTL for the signed view URLs (mirrors CreatorResource). */
    private const int SIGNED_URL_TTL_MINUTES = 60;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $relation = $this->resource;
        assert($relation instanceof AgencyCreatorRelation);

        $creator = $relation->creator;

        return [
            'id' => $relation->ulid,
            'type' => 'agency_creator_details',
            'attributes' => [
                // ── Relation block (the agency's private view) ──────────────
                'relationship_status' => $relation->relationship_status->value,
                'internal_rating' => $relation->internal_rating,
                'internal_notes' => $relation->internal_notes,
                'total_campaigns_completed' => $relation->total_campaigns_completed,
                'total_paid_minor_units' => $relation->total_paid_minor_units,
                'last_engaged_at' => $relation->last_engaged_at?->toIso8601String(),
                // Blacklist STATUS, read-only (D-2a-3). Structured facts only —
                // blacklist_reason is withheld (free-text GDPR-sensitive).
                'is_blacklisted' => $relation->is_blacklisted,
                'blacklist_scope' => $relation->blacklist_scope?->value,
                'blacklist_type' => $relation->blacklist_type?->value,
                'blacklisted_at' => $relation->blacklisted_at?->toIso8601String(),
                // ── Creator profile (nested) ────────────────────────────────
                'creator' => $creator instanceof Creator ? $this->mapCreator($creator) : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCreator(Creator $creator): array
    {
        return [
            'id' => $creator->ulid,
            'display_name' => $creator->display_name,
            'bio' => $creator->bio,
            // Contact email (D-2a-8). Lives on the related User; eager-loaded
            // by the controller. The agency-with-relation invariant (the
            // relation-exists tenancy check) is what makes this appropriate.
            'email' => $creator->user?->email,
            'country_code' => $creator->country_code,
            'region' => $creator->region,
            'primary_language' => $creator->primary_language,
            'secondary_languages' => $creator->secondary_languages,
            'categories' => $creator->categories,
            'avatar_url' => $this->signedViewUrl($creator->avatar_path),
            'cover_url' => $this->signedViewUrl($creator->cover_path),
            'application_status' => $creator->application_status->value,
            'social_accounts' => $this->mapSocialAccounts($creator),
            'portfolio' => $this->mapPortfolio($creator),
        ];
    }

    /**
     * Public summary of the connected social accounts (ACCOUNTS only — the
     * follower/engagement METRICS are blocked-on-data and render as an empty
     * state on the page, D-2a-10). OAuth tokens are never surfaced.
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
        $items = $creator->relationLoaded('portfolioItems')
            ? $creator->portfolioItems
            : $creator->portfolioItems()->get();

        return array_values(
            $items
                ->map(fn (CreatorPortfolioItem $item): array => [
                    'id' => $item->ulid,
                    'kind' => $item->kind->value,
                    'title' => $item->title,
                    'description' => $item->description,
                    's3_path' => $item->s3_path,
                    'view_url' => $this->signedViewUrl($item->s3_path),
                    'external_url' => $item->external_url,
                    'thumbnail_path' => $item->thumbnail_path,
                    'thumbnail_view_url' => $this->signedViewUrl($item->thumbnail_path),
                    'mime_type' => $item->mime_type,
                    'size_bytes' => $item->size_bytes,
                    'duration_seconds' => $item->duration_seconds,
                    'position' => $item->position,
                ])
                ->all(),
        );
    }

    /**
     * Mint a presigned GET URL against the private `media` disk, or null when
     * the path is null OR the disk is non-S3 (test fakes use the local driver,
     * which throws on temporaryUrl). Mirrors {@see CreatorResource::signedViewUrl()}.
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
