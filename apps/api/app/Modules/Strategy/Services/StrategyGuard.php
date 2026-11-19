<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Brands\Models\Brand;
use App\Modules\Waves\Models\Wave;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The three things every strategy write starts with (plan section 1.10): the archived-brand rule, the wave-row lock
 * (always FIRST, so two strategy writes and a wave edit are serialised per wave and cannot deadlock), and the one retry
 * a unique-index clash earns (the belt; with the lock it should never fire).
 */
final class StrategyGuard
{
    public static function assertBrandWritable(Brand $brand): void
    {
        if ($brand->trashed() || $brand->status === BrandStatus::Archived) {
            throw StrategyRuleViolation::brandArchived();
        }
    }

    /** Must be called inside a transaction. */
    public static function lockWave(Wave $wave): Wave
    {
        return Wave::query()->whereKey($wave->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $attempt
     * @return T
     */
    public static function retryOnClash(Closure $attempt): mixed
    {
        try {
            return $attempt();
        } catch (UniqueConstraintViolationException) {
            try {
                return $attempt();
            } catch (UniqueConstraintViolationException) {
                throw StrategyRuleViolation::conflict();
            }
        }
    }
}
