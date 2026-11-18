<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Resources;

use App\Modules\Agencies\Http\Resources\CreatorDiscoveryResource;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Services\WaveInviteOrigin;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Creators\Concerns\EmitsErasedState;
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
    use EmitsErasedState;

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
            // PL-1.2 — the label on a wave board's cards: present ONLY when the card's column is a wave column
            // (it carries a stage key) and the assignment is still at the invite stage. Absent on a normal board and
            // for any later status, so a normal board's JSON is byte-for-byte what it was.
            ...($this->inviteState($card) === null ? [] : [
                'invite_state' => $this->inviteState($card),
                // Only on the Invited column: it is what turns the label into "how they got here". The working
                // columns keep the assignment status label and the colour they always had.
                ...($this->inInvitedColumn($card) ? ['invite_origin' => $this->inviteOrigin($request, $card)] : []),
            ]),
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
                            'is_erased' => $this->creatorIsErased($creator),
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
    /**
     * `invited` (invited or countered), `accepted`, `declined`; null for anything else, and for a card that is not on
     * a wave column.
     *
     * In the Invited column `accepted` is every status that counts as selected ({@see AssignmentStatus::countsAsSelected()}):
     * acceptance moves the assignment on to contracted, so reading the one status Accepted missed it (walk, D3). In the
     * working columns the rule is what it was in PL-1.2 sub-step 4: only Accepted itself, so those cards are unchanged.
     */
    private function inviteState(BoardCard $card): ?string
    {
        if ($card->column?->stage_key === null) {
            return null;
        }

        $status = $card->assignment?->status;
        $accepted = $this->inInvitedColumn($card)
            ? $status?->countsAsSelected() === true
            : $status === AssignmentStatus::Accepted;

        return match (true) {
            $status === null => null,
            $status === AssignmentStatus::Invited, $status === AssignmentStatus::Countered => 'invited',
            $status === AssignmentStatus::Declined => 'declined',
            $accepted => 'accepted',
            default => null,
        };
    }

    private function inInvitedColumn(BoardCard $card): bool
    {
        return $card->column?->stage_key === 'invited';
    }

    /**
     * How the creator got to the Invited column: `application` when the agency accepted their application,
     * `invite` otherwise (see {@see WaveInviteOrigin}).
     */
    private function inviteOrigin(Request $request, BoardCard $card): string
    {
        $applied = $request->attributes->get(WaveInviteOrigin::ATTRIBUTE);
        $creatorId = $card->assignment?->creator_id;

        return is_array($applied) && $creatorId !== null && in_array($creatorId, $applied, true)
            ? 'application'
            : 'invite';
    }

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
