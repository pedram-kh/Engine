<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Policies\CreatorPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AH-051 (D-1) — PRE-DEPLOY visibility, READ-ONLY.
 *
 * The contact-details gate ({@see CreatorPolicy::canSeeContactDetails})
 * tightens from "any non-blacklisted relation" to "roster (connected) +
 * non-blacklisted". This command reports how many LIVE relations currently
 * expose creator contact details (phone / WhatsApp / mailing address) but will
 * lose that visibility after deploy — i.e. every NON-roster, non-blacklisted
 * relation. Pedram sees the number before the gate ships (recorded in the
 * review file).
 *
 * STRICTLY READ-ONLY: it issues aggregate SELECTs only and writes NOTHING. It
 * carries no --dry-run flag because it can never mutate. Safe to run against
 * production at any time; global tenancy scopes are bypassed so the count is
 * platform-wide, not agency-scoped.
 *
 * Output shape (stable — pinned by AuditContactExposureCommandTest):
 *   - one line per affected status with its relation count;
 *   - a "with contact data" subcount (relations whose creator actually has at
 *     least one of the four contact columns populated — the truly-exposing
 *     subset; a null-everywhere creator exposes an empty block);
 *   - a TOTAL line: "N relations across M agencies currently expose contact.".
 */
final class AuditContactExposure extends Command
{
    /** The four AH-005 contact columns on `creators`. */
    private const array CONTACT_COLUMNS = ['phone', 'whatsapp', 'address_street', 'address_postal_code'];

    protected $signature = 'relations:audit-contact-exposure';

    protected $description = 'READ-ONLY: report live relations that currently expose creator contact but lose it under the AH-051 roster-only gate.';

    public function handle(): int
    {
        // The relations that expose contact TODAY but not after AH-051 D-1:
        // every non-`roster`, non-blacklisted relation (null is_blacklisted
        // counts as not-blacklisted, the AH-005 convention).
        $base = AgencyCreatorRelation::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('relationship_status', '!=', RelationshipStatus::Roster->value)
            ->where(function ($query): void {
                $query->where('is_blacklisted', false)
                    ->orWhereNull('is_blacklisted');
            });

        /** @var array<string, int> $perStatus status value → relation count */
        $perStatus = (clone $base)
            ->select('relationship_status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('relationship_status')
            ->pluck('aggregate', 'relationship_status')
            ->map(static fn ($count): int => (int) $count)
            ->all();

        $total = array_sum($perStatus);
        $distinctAgencies = (clone $base)->distinct()->count('agency_id');

        // The truly-exposing subset: the creator actually has ≥1 contact value.
        $withContactData = (clone $base)
            ->whereHas('creator', function ($query): void {
                $query->where(function ($inner): void {
                    foreach (self::CONTACT_COLUMNS as $column) {
                        $inner->orWhereNotNull($column);
                    }
                });
            })
            ->count();

        $this->info('AH-051 D-1 contact-exposure audit (READ-ONLY, no writes).');
        $this->newLine();
        $this->line('Per-status breakdown of relations losing contact visibility:');

        // Deterministic order over every non-roster status (0 when absent) so
        // the shape never depends on which statuses happen to have rows.
        foreach (self::affectedStatuses() as $status) {
            $this->line(sprintf('  %-16s %d', $status->value, $perStatus[$status->value] ?? 0));
        }

        $this->newLine();
        $this->line(sprintf('  of which have contact data populated: %d', $withContactData));
        $this->newLine();
        $this->info(sprintf(
            '%d relation(s) across %d agenc%s currently expose contact.',
            $total,
            $distinctAgencies,
            $distinctAgencies === 1 ? 'y' : 'ies',
        ));

        return self::SUCCESS;
    }

    /**
     * Every status OTHER than roster — the affected set. Enumerated from the
     * enum so a new status is reported (never silently dropped).
     *
     * @return list<RelationshipStatus>
     */
    private static function affectedStatuses(): array
    {
        return array_values(array_filter(
            RelationshipStatus::cases(),
            static fn (RelationshipStatus $status): bool => $status !== RelationshipStatus::Roster,
        ));
    }
}
