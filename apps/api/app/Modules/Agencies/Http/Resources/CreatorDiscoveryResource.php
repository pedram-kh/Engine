<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Resources;

use App\Modules\Creators\Models\Creator;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * One CARD in the global discovery grid (Sprint 6.6a, D-5/D-8/D-10).
 *
 * The slim browse-row shape: the PUBLIC creator facts (display name, country,
 * primary language, categories) + a single signed avatar + the
 * "already-connected" annotation scoped to the CALLING agency only (D-4/D-7).
 *
 * What it carries vs WITHHOLDS — the privacy delta this resource exists to
 * enforce (D-5/D-7). It carries NONE of the per-agency relation block:
 *   - NO internal_notes / internal_rating (per-agency, GDPR-sensitive, leaky)
 *   - NO blacklist facts, NO counters, NO last_engaged_at (all per-agency)
 *   - NO contact email (a relation privilege, 2a D-2a-8 — no email pre-connect)
 *   - NO admin KYC PII
 * The ONLY per-agency datum exposed is `relationship_status` — and it is the
 * CALLING agency's own status, surfaced by the controller's calling-agency-
 * scoped annotation, NEVER any other agency's (the D-7 load-bearing invariant).
 *
 * Media boundary (D-10): a SINGLE signed avatar per card. The grid paginates
 * (25/page), so signing is bounded — mirrors {@see TalentPoolMemberResource},
 * NOT the unbounded N+1 the roster LIST avoids. The full portfolio is the
 * DETAIL resource's concern ({@see CreatorPublicProfileResource}).
 *
 * @mixin Creator
 */
final class CreatorDiscoveryResource extends JsonResource
{
    private const int SIGNED_URL_TTL_MINUTES = 60;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $creator = $this->resource;
        assert($creator instanceof Creator);

        // The calling-agency-scoped relation status, annotated by the
        // controller in ONE correlated subquery (D-4). null ⟹ no relation with
        // the CALLING agency. This is the ONLY relation datum on this shape and
        // it is the caller's OWN status — never another agency's (D-7).
        $connectedStatus = $creator->getAttribute('connected_relationship_status');
        $connectedStatus = is_string($connectedStatus) ? $connectedStatus : null;

        return [
            'id' => $creator->ulid,
            'type' => 'creator_discovery',
            'attributes' => [
                'display_name' => $creator->display_name,
                'country_code' => $creator->country_code,
                'primary_language' => $creator->primary_language,
                'accent' => $creator->accent,
                'categories' => $creator->categories,
                'avatar_url' => $this->signedViewUrl($creator->avatar_path),
                // The calling-agency-only relation status (D-4), emitted RAW
                // (Sprint 6.6b, D-5). The boolean `is_connected` was REMOVED:
                // it conflated `roster` with `pending_request`/`declined` (a
                // declined creator would have rendered "connected"). The FE now
                // derives the three states (connected / pending / declined /
                // none) from this status alone. null ⟹ no relation.
                'relationship_status' => $connectedStatus,
            ],
        ];
    }

    /**
     * Mint a presigned GET URL against the private `media` disk, or null when
     * the path is null OR the disk is non-S3 (test fakes use the local driver,
     * which throws on temporaryUrl). Mirrors {@see TalentPoolMemberResource}.
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
