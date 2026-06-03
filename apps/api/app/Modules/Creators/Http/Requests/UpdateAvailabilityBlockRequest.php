<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

/**
 * Validation for PATCH /api/v1/creators/me/availability/{block}
 * (Sprint 5 Chunk A).
 *
 * Update is a full-resource replace — an availability block's fields are
 * interdependent (the starts_at/ends_at pair, the is_recurring/recurrence_rule
 * pair), so a partial-field PATCH invites half-valid states. The edit modal
 * submits the whole block, so the rules are identical to
 * {@see StoreAvailabilityBlockRequest}.
 */
final class UpdateAvailabilityBlockRequest extends StoreAvailabilityBlockRequest {}
