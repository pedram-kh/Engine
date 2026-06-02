<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Lifecycle status of a `contracts` row (docs/03-DATA-MODEL.md §8, `:589`).
 *
 *   draft      → authored, not yet dispatched
 *   sent       → vendor envelope dispatched; awaiting signature
 *   signed     → terminal success — a binding signature is on file. The
 *                click-through acceptance lands here too: per the master
 *                agreement §10 the click-through "constitutes a binding
 *                electronic signature with the same legal effect". The
 *                click-through vs vendor distinction is carried by
 *                `signature_provider` (`internal` vs docusign/dropboxsign),
 *                NOT by a separate status value (the spec enum has none).
 *   declined   → terminal failure — signer declined
 *   expired    → terminal failure — envelope TTL elapsed unsigned
 *   superseded → replaced by a newer version of the same agreement
 */
enum ContractStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Signed = 'signed';
    case Declined = 'declined';
    case Expired = 'expired';
    case Superseded = 'superseded';
}
