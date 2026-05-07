<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Auto-populates a `ulid` column on model creation and uses it as the
 * default route-model-binding key.
 *
 * Distinct from Laravel's `HasUlids` trait, which makes the ULID the
 * primary key. Our convention (docs/02-CONVENTIONS.md §2.6, docs/03-DATA-MODEL.md §1):
 *   - `id`   — bigint, internal, used for foreign keys and joins
 *   - `ulid` — char(26), public identifier exposed in every API resource
 *
 * Any model that also exposes itself in a URL should declare a
 * char(26) `ulid` column with a unique index and apply this trait.
 *
 * @mixin Model
 *
 * @property string $ulid
 */
trait HasUlid
{
    public static function bootHasUlid(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('ulid'))) {
                $model->setAttribute('ulid', (string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
