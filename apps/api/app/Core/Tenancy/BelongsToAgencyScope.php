<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters tenant-scoped queries by the current request's agency.
 *
 * If no agency context is set (e.g., during console commands, queue
 * workers, or admin SPA requests), the scope does NOT filter — the
 * caller is expected to be either (a) operating intentionally
 * cross-tenant with audit consequences, or (b) using
 * `Model::withoutGlobalScope(BelongsToAgencyScope::class)` explicitly.
 *
 * The trait that installs this scope (`BelongsToAgency`) also enforces
 * `agency_id` is set at insert time, so a missing context never
 * silently writes a row to the wrong tenant.
 */
final class BelongsToAgencyScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenancyContext::class);

        if (! $context->hasAgency()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('agency_id'),
            $context->agencyId(),
        );
    }
}
