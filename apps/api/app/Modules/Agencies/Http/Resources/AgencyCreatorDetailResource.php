<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Resources;

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorSocialAccount;
use App\Modules\Creators\Policies\CreatorPolicy;
use App\Modules\Creators\Support\PortfolioItemPresenter;
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
     * AH-005 — whether to embed the creator's optional CONTACT block (phone /
     * WhatsApp / mailing street + postal code) in the nested creator profile.
     * Toggled by {@see self::withContact()}; the controller computes the gate
     * ({@see CreatorPolicy::canSeeContactDetails})
     * because only it holds the calling user + agency. Default false → the
     * block is OMITTED ENTIRELY (no keys), so a blacklisted-rostered agency
     * receives zero contact data. This withhold is the load-bearing privacy
     * pin (break-revert: loosen the controller gate to relation-exists → the
     * blacklisted-agency-sees-nothing spec fails).
     */
    private bool $includeContact = false;

    /**
     * Fluent setter — mirror of {@see CreatorResource::withAdmin()}. The
     * controller chains this with the result of the contact-visibility gate:
     *
     *   (new AgencyCreatorDetailResource($relation))
     *       ->withContact($canSeeContact)->response($request);
     */
    public function withContact(bool $includeContact = true): self
    {
        $this->includeContact = $includeContact;

        return $this;
    }

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
        $payload = [
            'id' => $creator->ulid,
            'display_name' => $creator->display_name,
            'bio' => $creator->bio,
            // Contact email (D-2a-8). Lives on the related User; eager-loaded
            // by the controller. The agency-with-relation invariant (the
            // relation-exists tenancy check) is what makes this appropriate.
            'email' => $creator->user?->email,
            // Account-creation identity (sign-up first/last name) — same
            // relation-exists privacy basis as the email. NEVER on discover.
            'account_name' => $creator->user?->name,
            'account_last_name' => $creator->user?->last_name,
            'country_code' => $creator->country_code,
            'region' => $creator->region,
            'primary_language' => $creator->primary_language,
            'secondary_languages' => $creator->secondary_languages,
            'accent' => $creator->accent,
            'categories' => $creator->categories,
            'avatar_url' => $this->signedViewUrl($creator->avatar_path),
            'cover_url' => $this->signedViewUrl($creator->cover_path),
            'application_status' => $creator->application_status->value,
            'social_accounts' => $this->mapSocialAccounts($creator),
            'portfolio' => $this->mapPortfolio($creator),
        ];

        // AH-005 — the optional contact block, gated by the controller. The
        // mailing address composes from country_code + region (the city line)
        // + these two new lines. Keys are present ONLY when the gate passed,
        // so a withheld view carries no phone/whatsapp/address_* keys at all.
        if ($this->includeContact) {
            $payload['phone'] = $creator->phone;
            $payload['whatsapp'] = $creator->whatsapp;
            $payload['address_street'] = $creator->address_street;
            $payload['address_postal_code'] = $creator->address_postal_code;
        }

        return $payload;
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
        // AH-004: shared presenter applies the server-authoritative `ready`-gate
        // (signed URLs withheld for processing/failed items) uniformly with the
        // creator-owner and admin surfaces.
        return (new PortfolioItemPresenter)->mapForCreator($creator);
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
