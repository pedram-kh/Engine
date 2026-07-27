<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Brands\Models\Brand;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * AH-053 (D9) — PRE-DEPLOY visibility, READ-ONLY. The AH-051
 * `relations:audit-contact-exposure` shape.
 *
 * D6 introduces a brand FLOOR ({@see Brand::FLOOR_FIELDS}) that the API
 * enforces on every write: an existing brand that is missing any floor field
 * is hard-blocked on its NEXT EDIT until it is completed. Nothing is touched
 * at rest — an incomplete brand keeps working for reading, listing and
 * campaign attachment indefinitely — so the blast radius is exactly "how many
 * brands will hit a wall the first time someone edits them".
 *
 * This command reports that number BEFORE the gate ships. Pedram runs it
 * against production and records the output in the review file.
 *
 * STRICTLY READ-ONLY: aggregate SELECTs only, no writes, no flags (there is
 * nothing to dry-run). Global tenancy scopes are bypassed so the count is
 * platform-wide; soft-deleted brands are included, since a restore followed
 * by an edit meets the same gate.
 *
 * Output shape (stable — pinned by AuditBrandFloorCommandTest):
 *   - one line per floor field with the number of brands it blocks (a brand
 *     missing three fields appears in three rows — this is a per-field
 *     breakdown, deliberately NOT a partition);
 *   - a lifecycle split (active / archived / soft-deleted) over the blocked
 *     brands;
 *   - a TOTAL line: "N of M brand(s) across K agenc(y|ies) fail the floor.".
 */
final class AuditBrandFloor extends Command
{
    protected $signature = 'brands:audit-floor';

    protected $description = 'READ-ONLY: report brands that fail the AH-053 completeness floor and will hard-block on their next edit.';

    public function handle(): int
    {
        $total = self::baseQuery()->count();
        $failing = self::failingQuery();

        $failingCount = (clone $failing)->count();
        $distinctAgencies = (clone $failing)->distinct()->count('agency_id');

        $this->info('AH-053 D6 brand-floor audit (READ-ONLY, no writes).');
        $this->newLine();
        $this->line('Brands blocked by each floor field (a brand may be counted in several rows):');

        // Enumerated from the floor constant, so a future floor field is
        // reported rather than silently dropped.
        foreach (Brand::FLOOR_FIELDS as $field) {
            $count = self::baseQuery()
                ->where(fn (Builder $query): Builder => self::whereFieldEmpty($query, $field))
                ->count();

            $this->line(sprintf('  %-16s %d', $field, $count));
        }

        $this->newLine();
        $this->line('Lifecycle split of the blocked brands:');
        $this->line(sprintf('  %-16s %d', 'active', (clone $failing)->whereNull('deleted_at')->where('status', BrandStatus::Active->value)->count()));
        $this->line(sprintf('  %-16s %d', 'archived', (clone $failing)->whereNull('deleted_at')->where('status', BrandStatus::Archived->value)->count()));
        $this->line(sprintf('  %-16s %d', 'soft-deleted', (clone $failing)->whereNotNull('deleted_at')->count()));

        $this->newLine();
        $this->info(sprintf(
            '%d of %d brand(s) across %d agenc%s fail the floor.',
            $failingCount,
            $total,
            $distinctAgencies,
            $distinctAgencies === 1 ? 'y' : 'ies',
        ));

        return self::SUCCESS;
    }

    /**
     * Every brand on the platform: tenancy bypassed, soft-deleted included.
     *
     * @return Builder<Brand>
     */
    private static function baseQuery(): Builder
    {
        return Brand::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->withTrashed();
    }

    /**
     * Brands missing AT LEAST ONE floor field — the set that hard-blocks.
     *
     * @return Builder<Brand>
     */
    private static function failingQuery(): Builder
    {
        return self::baseQuery()->where(function (Builder $query): void {
            foreach (Brand::FLOOR_FIELDS as $field) {
                $query->orWhere(fn (Builder $inner): Builder => self::whereFieldEmpty($inner, $field));
            }
        });
    }

    /**
     * SQL twin of {@see Brand::isFilled()}: null or whitespace-only counts as
     * empty, so the report cannot disagree with the gate.
     *
     * @param  Builder<Brand>  $query
     * @return Builder<Brand>
     */
    private static function whereFieldEmpty(Builder $query, string $field): Builder
    {
        // Belt-and-braces: the only caller iterates a class constant, but the
        // column name reaches raw SQL, so it is checked against that constant
        // rather than trusted by provenance.
        if (! in_array($field, Brand::FLOOR_FIELDS, true)) {
            throw new InvalidArgumentException("Not a brand floor field: {$field}");
        }

        return $query->whereNull($field)->orWhereRaw("TRIM({$field}) = ''");
    }
}
