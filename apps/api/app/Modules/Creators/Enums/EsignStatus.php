<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * E-signature envelope status on the wizard's master-contract step.
 *
 *   sent      → envelope dispatched; awaiting creator signature
 *   signed    → terminal success — wizard advances + signed_master_contract_id is set
 *   declined  → terminal failure — creator declined to sign; admin follow-up
 *   expired   → terminal failure — creator never signed within the vendor's TTL
 *
 * Returned from {@see EsignProvider::getEnvelopeStatus()} and matched
 * against {@see EsignWebhookEvent} payloads. Not currently persisted
 * on the Creator row — `signed_master_contract_id` carries the
 * positive case; declined/expired cases re-trigger the wizard step.
 *
 * Sprint 3 Chunk 2 introduces this enum alongside the EsignProvider
 * contract extension (Q-mock-webhook-dispatch decisions; see
 * docs/06-INTEGRATIONS.md § 4.2). Sprint 9's real e-sign adapter
 * MUST map vendor-specific event names onto these four cases.
 */
enum EsignStatus: string
{
    case Sent = 'sent';
    case Signed = 'signed';
    case Declined = 'declined';
    case Expired = 'expired';
}
