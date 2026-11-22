<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Notes;

use App\Core\Tenancy\CurrentAgencyRole;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WaveStrategyNote;
use Illuminate\Support\Collection;

/**
 * The wire shape of an internal note (PL-2.5). Ulids only on the wire. An author who is gone, or no longer an active member
 * of the agency, is sent as `author: null` with `author_is_former: true` and NO name (the tracker's `owner_is_former` rule).
 * The ONLY presenter that ever serialises a note body (`StrategyNoteIsolationTest`).
 */
final class NotePresenter
{
    /**
     * @param  Collection<int, WaveStrategyNote>  $notes
     * @return list<array<string, mixed>>
     */
    public static function thread(Collection $notes, User $viewer, int $agencyId): array
    {
        $ids = $notes->pluck('author_user_id')->filter()->unique()->values()->all();
        $active = $ids === [] ? [] : AgencyMembership::query()
            ->where('agency_id', $agencyId)
            ->whereIn('user_id', $ids)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $canManage = CurrentAgencyRole::canManage($viewer);

        return array_values($notes->map(static function (WaveStrategyNote $n) use ($active, $viewer, $canManage): array {
            $author = $n->author;
            $isActive = $author !== null && in_array((int) $author->id, $active, true);
            $own = $author !== null && $n->author_user_id === $viewer->id;

            return self::one($n, $isActive ? $author : null, $own, $own || $canManage);
        })->values()->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(WaveStrategyNote $n, ?User $activeAuthor, bool $canEdit, bool $canDelete): array
    {
        return [
            'id' => $n->ulid,
            'type' => 'wave_strategy_notes',
            'attributes' => [
                'step' => $n->step,
                'market' => $n->market?->ulid,
                'body' => $n->body,
                'author' => $activeAuthor === null ? null : ['id' => $activeAuthor->ulid, 'name' => (string) $activeAuthor->name],
                'author_is_former' => $activeAuthor === null,
                'created_at' => $n->created_at->toIso8601String(),
                'edited' => $n->edited_at !== null,
                'edited_at' => $n->edited_at?->toIso8601String(),
                'can_edit' => $canEdit,
                'can_delete' => $canDelete,
            ],
        ];
    }
}
