<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\ValueObjects;

use App\Modules\Campaigns\Http\Requests\AcceptApplicationRequest;
use App\Modules\Campaigns\Http\Requests\InviteAssignmentRequest;
use App\Modules\Campaigns\Services\CampaignInvitationService;

/**
 * The OFFER an agency puts in front of a creator (AH-058 sub-step 1).
 *
 * Extracted so the two paths that create an invitation — the direct invite
 * (`CampaignAssignmentController::store()`) and accept-an-application
 * (`CampaignApplicationController::accept()`) — cannot drift on the offer's
 * shape. Both build one of these from their own validated payload and hand it to
 * {@see CampaignInvitationService::invite()}; neither reaches into the other's
 * request class.
 *
 * Currency is upper-cased HERE rather than at each call site: it was normalized
 * inline in `store()` before this object existed, and a second call site is
 * exactly how that kind of normalization goes missing on one path.
 *
 * The attachment quad travels as one nullable group because that is how it is
 * validated and how it is written — four independently-nullable columns that are
 * either all set or all null.
 *
 * @see InviteAssignmentRequest  the direct-invite payload
 * @see AcceptApplicationRequest the accept-an-application payload (same rules)
 */
final readonly class AssignmentOffer
{
    public function __construct(
        public int $agreedFeeMinorUnits,
        public string $agreedFeeCurrency,
        public ?string $feePer = null,
        public ?string $offerDescription = null,
        public ?string $attachmentPath = null,
        public ?string $attachmentName = null,
        public ?string $attachmentMime = null,
        public ?int $attachmentSizeBytes = null,
        public ?string $deliverables = null,
        public ?string $postingDueAt = null,
        public ?string $draftDueAt = null,
    ) {}

    /**
     * Build from a validated invite/accept payload.
     *
     * The `attachment` sub-array is the presigned-upload descriptor the request
     * classes share (`upload_id` / `name` / `mime_type` / `size_bytes`), already
     * prefix-verified against the campaign by the caller — this object does no
     * authorization of its own.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        /** @var array{upload_id: string, name: string, mime_type: string, size_bytes: int}|null $attachment */
        $attachment = $validated['attachment'] ?? null;

        return new self(
            agreedFeeMinorUnits: (int) $validated['agreed_fee_minor_units'],
            agreedFeeCurrency: strtoupper((string) $validated['agreed_fee_currency']),
            feePer: self::nullableString($validated['fee_per'] ?? null),
            offerDescription: self::nullableString($validated['offer_description'] ?? null),
            attachmentPath: $attachment === null ? null : (string) $attachment['upload_id'],
            attachmentName: $attachment === null ? null : (string) $attachment['name'],
            attachmentMime: $attachment === null ? null : (string) $attachment['mime_type'],
            attachmentSizeBytes: $attachment === null ? null : (int) $attachment['size_bytes'],
            deliverables: self::nullableString($validated['deliverables'] ?? null),
            postingDueAt: self::nullableString($validated['posting_due_at'] ?? null),
            draftDueAt: self::nullableString($validated['draft_due_at'] ?? null),
        );
    }

    /**
     * The attachment quad as the machine's re-offer signature expects it, or
     * null when there is no attachment.
     *
     * `CampaignAssignmentStateMachine::reofferAfterDecline()` takes the quad as
     * a `path/name/mime/size` array (its own shape, predating this object), so
     * the mapping lives here rather than being re-spelled at the two call sites
     * that re-offer.
     *
     * @return array{path: string, name: string, mime: string, size: int}|null
     */
    public function attachmentForReoffer(): ?array
    {
        if ($this->attachmentPath === null) {
            return null;
        }

        return [
            'path' => $this->attachmentPath,
            'name' => (string) $this->attachmentName,
            'mime' => (string) $this->attachmentMime,
            'size' => (int) $this->attachmentSizeBytes,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
