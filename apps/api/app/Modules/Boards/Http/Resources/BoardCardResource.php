<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Resources;

use App\Modules\Agencies\Http\Resources\CreatorDiscoveryResource;
use App\Modules\Boards\Models\BoardCard;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * JSON representation of a board card (docs/04-API-DESIGN.md §4 envelope).
 *
 * A card IS a CampaignAssignment (§4.1) — the card-face data (creator, status,
 * deliverables) is surfaced from the eager-loaded assignment so the Chunk 2 SPA
 * renders the card without a second fetch. `position` is present per §10 but
 * INERT in P1 (intra-column ordering is P2).
 *
 * Card-face media/offer additions (board-card facelift): a single signed
 * `avatar_url` (bounded — the board loads one page of cards, mirrors
 * {@see CreatorDiscoveryResource}) plus
 * the agreed fee (`agreed_fee_minor_units` / `agreed_fee_currency`) and its
 * free-text unit (`fee_per`), so the card can lead with the creator photo and
 * anchor the offer fee. All are already-loaded columns — no new persistence.
 *
 * @mixin BoardCard
 */
final class BoardCardResource extends JsonResource
{
    private const int SIGNED_URL_TTL_MINUTES = 60;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $card = $this->resource;
        assert($card instanceof BoardCard);

        $assignment = $card->assignment;
        $creator = $assignment?->creator;

        return [
            'id' => $card->ulid,
            'type' => 'board_cards',
            'attributes' => [
                'position' => $card->position,
                'created_at' => $card->created_at->toIso8601String(),
                'updated_at' => $card->updated_at->toIso8601String(),
            ],
            'relationships' => [
                'column' => [
                    'data' => ['id' => $card->column?->ulid, 'type' => 'board_columns'],
                ],
                'assignment' => [
                    'data' => $assignment === null ? null : [
                        'id' => $assignment->ulid,
                        'type' => 'campaign_assignments',
                        'status' => $assignment->status->value,
                        // Decline-history marker (re-offer-after-decline chunk)
                        // — lets the card face + drawer show a "was declined,
                        // re-invited" tag even after the status flips back to
                        // `invited`. Same flag the Creators tab reads.
                        'previously_declined' => $assignment->previously_declined,
                        'deliverables' => $assignment->deliverables,
                        'posting_due_at' => $assignment->posting_due_at?->toIso8601String(),
                        // Offer fee for the card face (board-card facelift):
                        // amount + free-text unit ("€200 / script").
                        'agreed_fee_minor_units' => $assignment->agreed_fee_minor_units,
                        'agreed_fee_currency' => $assignment->agreed_fee_currency,
                        'fee_per' => $assignment->fee_per,
                        'creator' => $creator === null ? null : [
                            'id' => $creator->ulid,
                            'display_name' => $creator->display_name,
                            'avatar_url' => $this->signedViewUrl($creator->avatar_path),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Mint a presigned GET URL against the private `media` disk, or null when
     * the path is null OR the disk is non-S3 (test fakes use the local driver,
     * which throws on temporaryUrl). Mirrors {@see CreatorDiscoveryResource}.
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
