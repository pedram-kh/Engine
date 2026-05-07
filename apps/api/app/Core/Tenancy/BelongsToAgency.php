<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Modules\Agencies\Models\Agency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-scoped (belongs to an Agency).
 *
 * What this trait does:
 *   - Installs the BelongsToAgencyScope global scope so every query is
 *     automatically filtered by the current request's agency context.
 *   - Throws on create() if `agency_id` is missing AND no tenant
 *     context is active, so a stray write never lands in the wrong
 *     tenant.
 *   - Auto-fills `agency_id` from TenancyContext when the column is
 *     not set explicitly but a context IS active.
 *   - Provides the `agency()` relationship.
 *
 * Models that intentionally bypass the scope use
 *   Model::withoutGlobalScope(BelongsToAgencyScope::class)
 * at the call site so the bypass is visible in code review.
 *
 * See docs/00-MASTER-ARCHITECTURE.md §4 and docs/03-DATA-MODEL.md §20.
 *
 * @mixin Model
 *
 * @property int $agency_id
 * @property-read Agency $agency
 */
trait BelongsToAgency
{
    public static function bootBelongsToAgency(): void
    {
        static::addGlobalScope(new BelongsToAgencyScope);

        static::creating(function (Model $model): void {
            if (! empty($model->getAttribute('agency_id'))) {
                return;
            }

            $context = app(TenancyContext::class);

            if ($context->hasAgency()) {
                $model->setAttribute('agency_id', $context->agencyId());

                return;
            }

            throw MissingAgencyContextException::onCreate(static::class);
        });
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
