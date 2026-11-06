<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Support\ContactMediaUrl;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AH-119 A2 — the unified agency inbox: ONE query over both thread tables.
 *
 *  - A thread surfaces only with a human (non-system) message that is not deleted (Q2).
 *  - Unread is per viewer, set-based (one grouped NOT EXISTS per table, joined, never per thread),
 *    and counts human messages only (Q2): system lines add nothing to a count or to surfacing.
 *  - Order and cursor: `(last message time, type, id)` descending.
 *  - Filters: brand[] and campaign[] (ulids that do not belong to the agency are ignored, never an
 *    error, so nothing is leaked), unread, owner (me|all). Direct rows are hidden under a brand or a
 *    campaign filter.
 *  - Facets: brands and campaigns with the number of THREADS with unread; each facet ignores its own
 *    selection (and `unread`), and follows `owner` and the other facet. The pill is the unfiltered
 *    total of threads with unread (P6).
 *  - Owner: `coalesce(owner_user_id, invited_by_user_id)` of the creator relation, read at query time
 *    (no backfill). Assignment rows take their creator's owner.
 *
 * Every source selects `where agency_id = ?` explicitly (raw query builder: no global scope applies).
 */
final class MessageInboxQuery
{
    public const TYPE_RELATIONSHIP = 'relationship_thread';

    public const TYPE_ASSIGNMENT = 'assignment_thread';

    public const DEFAULT_PER_PAGE = 30;

    public const MAX_PER_PAGE = 100;

    private const PREVIEW_LENGTH = 200;

    /**
     * @param  array{brand?: list<string>, campaign?: list<string>, unread?: bool, owner?: string, direct?: bool, per_page?: int, cursor?: string|null}  $params
     * @return array{rows: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function run(Agency $agency, User $viewer, array $params): array
    {
        $brandIds = $this->resolveIds(Brand::class, $agency, $params['brand'] ?? []);
        $campaignIds = $this->resolveIds(Campaign::class, $agency, $params['campaign'] ?? []);
        $unreadOnly = (bool) ($params['unread'] ?? false);
        $mine = ($params['owner'] ?? 'all') === 'me';
        $directOnly = (bool) ($params['direct'] ?? false);
        $perPage = min(max((int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        $union = $this->threads($agency, $viewer);

        $page = $this->filtered($union, $brandIds, $campaignIds, $unreadOnly, $mine, $viewer, $directOnly)
            ->orderByDesc('last_at')->orderByDesc('type')->orderByDesc('id');
        $this->applyCursor($page, $params['cursor'] ?? null);

        $fetched = $page->limit($perPage + 1)->get();
        $hasMore = $fetched->count() > $perPage;
        $rows = $fetched->take($perPage)->values();

        $last = $rows->last();

        return [
            'rows' => $this->present($agency, array_values($rows->all())),
            'meta' => [
                'unread_threads_total' => $this->unreadThreads($this->filtered($union, [], [], false, false, $viewer)),
                'facets' => [
                    'brands' => $this->brandFacet($agency, $union, $campaignIds, $mine, $viewer),
                    'campaigns' => $this->campaignFacet($agency, $union, $brandIds, $mine, $viewer),
                ],
                'next_cursor' => $hasMore && $last !== null
                    ? base64_encode((string) json_encode([$last->last_at, $last->type, (int) $last->id]))
                    : null,
            ],
        ];
    }

    /**
     * The inbox rows (the exact shape of {@see self::run()}'s `rows`) of the given threads, for the
     * message search. Same tenancy (`where agency_id` on every source), same unread and preview
     * rules; a thread without a surfaceable human message simply yields no row.
     *
     * @param  list<int>  $relationshipThreadIds
     * @param  list<int>  $assignmentThreadIds
     * @return array<string, array<string, mixed>> keyed `type:thread ulid`
     */
    public function rowsForThreads(Agency $agency, User $viewer, array $relationshipThreadIds, array $assignmentThreadIds): array
    {
        if ($relationshipThreadIds === [] && $assignmentThreadIds === []) {
            return [];
        }

        $q = clone $this->threads($agency, $viewer);
        $q->select('inbox.*')->where(function ($w) use ($relationshipThreadIds, $assignmentThreadIds): void {
            $w->where(fn ($x) => $x->where('inbox.type', self::TYPE_RELATIONSHIP)->whereIn('inbox.id', $relationshipThreadIds))
                ->orWhere(fn ($x) => $x->where('inbox.type', self::TYPE_ASSIGNMENT)->whereIn('inbox.id', $assignmentThreadIds));
        });

        $rows = array_values($q->get()->all());
        $out = [];
        foreach ($this->present($agency, $rows) as $row) {
            $out[$row['type'].':'.$row['id']] = $row;
        }

        return $out;
    }

    /**
     * The union of both sources as a derived table `inbox` with one uniform row shape.
     */
    private function threads(Agency $agency, User $viewer): Builder
    {
        $relationshipUnread = DB::table('relationship_messages as m')
            ->selectRaw('m.thread_id, count(*) as unread')
            ->whereNull('m.deleted_at')
            ->where('m.sender_user_id', '!=', $viewer->id)
            ->whereNotExists(function ($q) use ($viewer): void {
                $q->selectRaw('1')->from('relationship_message_read_receipts as r')
                    ->whereColumn('r.message_id', 'm.id')->where('r.user_id', $viewer->id);
            })
            ->groupBy('m.thread_id');

        $relationshipHuman = DB::table('relationship_messages as hm')
            ->selectRaw('1')->whereColumn('hm.thread_id', 't.id')->whereNull('hm.deleted_at');

        $relationship = DB::table('relationship_threads as t')
            ->leftJoin('agency_creator_relations as acr', function ($j): void {
                $j->on('acr.agency_id', '=', 't.agency_id')->on('acr.creator_id', '=', 't.creator_id');
            })
            ->leftJoinSub($relationshipUnread, 'u', 'u.thread_id', '=', 't.id')
            ->where('t.agency_id', $agency->id)
            ->whereExists($relationshipHuman)
            ->selectRaw(
                "'".self::TYPE_RELATIONSHIP."' as type, t.id as id, t.ulid as ulid, "
                .'coalesce(t.last_message_at, t.created_at) as last_at, t.creator_id as creator_id, '
                .'cast(null as bigint) as assignment_id, cast(null as bigint) as campaign_id, cast(null as bigint) as brand_id, '
                .'coalesce(acr.owner_user_id, acr.invited_by_user_id) as owner_id, coalesce(u.unread, 0) as unread',
            );

        $assignmentUnread = DB::table('messages as m')
            ->selectRaw('m.thread_id, count(*) as unread')
            ->whereNull('m.deleted_at')
            ->where('m.kind', '!=', MessageKind::System->value)
            ->where('m.sender_user_id', '!=', $viewer->id)
            ->whereNotExists(function ($q) use ($viewer): void {
                $q->selectRaw('1')->from('message_read_receipts as r')
                    ->whereColumn('r.message_id', 'm.id')->where('r.user_id', $viewer->id);
            })
            ->groupBy('m.thread_id');

        $assignmentHuman = DB::table('messages as hm')
            ->selectRaw('1')->whereColumn('hm.thread_id', 't.id')->whereNull('hm.deleted_at')
            ->where('hm.kind', '!=', MessageKind::System->value);

        $assignment = DB::table('message_threads as t')
            ->join('campaign_assignments as a', 'a.id', '=', 't.assignment_id')
            ->join('campaigns as c', 'c.id', '=', 'a.campaign_id')
            ->leftJoin('agency_creator_relations as acr', function ($j): void {
                $j->on('acr.agency_id', '=', 't.agency_id')->on('acr.creator_id', '=', 'a.creator_id');
            })
            ->leftJoinSub($assignmentUnread, 'u', 'u.thread_id', '=', 't.id')
            ->where('t.agency_id', $agency->id)
            ->whereExists($assignmentHuman)
            ->selectRaw(
                "'".self::TYPE_ASSIGNMENT."' as type, t.id as id, t.ulid as ulid, "
                .'coalesce(t.last_message_at, t.created_at) as last_at, a.creator_id as creator_id, '
                .'a.id as assignment_id, a.campaign_id as campaign_id, c.brand_id as brand_id, '
                .'coalesce(acr.owner_user_id, acr.invited_by_user_id) as owner_id, coalesce(u.unread, 0) as unread',
            );

        return DB::query()->fromSub($relationship->unionAll($assignment), 'inbox');
    }

    /**
     * @param  list<int>  $brandIds
     * @param  list<int>  $campaignIds
     */
    private function filtered(Builder $union, array $brandIds, array $campaignIds, bool $unreadOnly, bool $mine, User $viewer, bool $directOnly = false): Builder
    {
        $q = clone $union;
        $q->select('inbox.*');

        if ($directOnly) {
            // Direct = relationship threads. With a brand or campaign filter too, nothing is left
            // (the web never sends both: choosing one clears the other).
            $q->where('inbox.type', self::TYPE_RELATIONSHIP);
        }

        if ($brandIds !== [] || $campaignIds !== []) {
            // Direct rows carry no brand or campaign: hidden under either filter.
            $q->where('inbox.type', self::TYPE_ASSIGNMENT);
        }
        if ($brandIds !== []) {
            $q->whereIn('inbox.brand_id', $brandIds);
        }
        if ($campaignIds !== []) {
            $q->whereIn('inbox.campaign_id', $campaignIds);
        }
        if ($unreadOnly) {
            $q->where('inbox.unread', '>', 0);
        }
        if ($mine) {
            $q->where('inbox.owner_id', $viewer->id);
        }

        return $q;
    }

    private function unreadThreads(Builder $q): int
    {
        return (int) $q->where('inbox.unread', '>', 0)->count();
    }

    private function applyCursor(Builder $q, ?string $cursor): void
    {
        if ($cursor === null || $cursor === '') {
            return;
        }
        $decoded = json_decode((string) base64_decode($cursor, true), true);
        if (! is_array($decoded) || count($decoded) !== 3) {
            return;
        }
        [$at, $type, $id] = $decoded;
        if (! is_string($at) || ! is_string($type) || ! is_int($id)) {
            return;
        }

        $q->where(function ($w) use ($at, $type, $id): void {
            $w->where('inbox.last_at', '<', $at)
                ->orWhere(function ($x) use ($at, $type, $id): void {
                    $x->where('inbox.last_at', '=', $at)->where(function ($y) use ($type, $id): void {
                        $y->where('inbox.type', '<', $type)
                            ->orWhere(fn ($z) => $z->where('inbox.type', '=', $type)->where('inbox.id', '<', $id));
                    });
                });
        });
    }

    /**
     * Brand facet: ignores the brand selection and `unread`; follows owner and the campaign selection.
     *
     * @param  list<int>  $campaignIds
     * @return array<int, array<string, mixed>>
     */
    private function brandFacet(Agency $agency, Builder $union, array $campaignIds, bool $mine, User $viewer): array
    {
        $q = $this->filtered($union, [], $campaignIds, false, $mine, $viewer)
            ->where('inbox.type', self::TYPE_ASSIGNMENT);
        $q->columns = null;
        $q->selectRaw('inbox.brand_id as facet_id, count(*) as threads, sum(case when inbox.unread > 0 then 1 else 0 end) as unread_threads')
            ->groupBy('inbox.brand_id');

        $counts = $q->get()->keyBy('facet_id');
        if ($counts->isEmpty()) {
            return [];
        }

        return Brand::query()->where('agency_id', $agency->id)->withTrashed()
            ->whereIn('id', $counts->keys()->all())->orderBy('name')->get()
            ->map(fn (Brand $b): array => [
                'id' => $b->ulid,
                'name' => $b->name,
                'color' => $b->color,
                'unread_threads' => (int) ($counts[$b->id]->unread_threads ?? 0),
            ])->values()->all();
    }

    /**
     * Campaign facet: ignores the campaign selection and `unread`; follows owner and the brand selection.
     *
     * @param  list<int>  $brandIds
     * @return array<int, array<string, mixed>>
     */
    private function campaignFacet(Agency $agency, Builder $union, array $brandIds, bool $mine, User $viewer): array
    {
        $q = $this->filtered($union, $brandIds, [], false, $mine, $viewer)
            ->where('inbox.type', self::TYPE_ASSIGNMENT);
        $q->columns = null;
        $q->selectRaw('inbox.campaign_id as facet_id, count(*) as threads, sum(case when inbox.unread > 0 then 1 else 0 end) as unread_threads')
            ->groupBy('inbox.campaign_id');

        $counts = $q->get()->keyBy('facet_id');
        if ($counts->isEmpty()) {
            return [];
        }

        return Campaign::query()->where('agency_id', $agency->id)->withTrashed()
            ->with('brand:id,ulid,color')
            ->whereIn('id', $counts->keys()->all())->orderBy('name')->get()
            ->map(fn (Campaign $c): array => [
                'id' => $c->ulid,
                'name' => $c->name,
                'color' => $c->brand?->color,
                'brand_id' => $c->brand?->ulid,
                'unread_threads' => (int) ($counts[$c->id]->unread_threads ?? 0),
            ])->values()->all();
    }

    /**
     * @param  class-string<Brand|Campaign>  $model
     * @param  list<string>  $ulids
     * @return list<int>
     */
    private function resolveIds(string $model, Agency $agency, array $ulids): array
    {
        $ulids = array_values(array_filter($ulids, 'is_string'));
        if ($ulids === []) {
            return [];
        }

        // A ulid from another agency simply does not resolve: ignored, never a 404 or a 403.
        $ids = $model::query()->withTrashed()->where('agency_id', $agency->id)
            ->whereIn('ulid', $ulids)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return array_values($ids);
    }

    /**
     * Turn the page of raw rows into resources. A fixed number of queries whatever the page size.
     *
     * @param  list<\stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(Agency $agency, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $creators = Creator::query()->withTrashed()
            ->whereIn('id', array_values(array_unique(array_map(fn ($r): int => (int) $r->creator_id, $rows))))
            ->get(['id', 'ulid', 'display_name', 'avatar_path', 'erased_at'])->keyBy('id');

        $assignments = $this->assignmentContext($agency, $rows);
        $previews = $this->previews($rows);
        $owners = $this->owners($agency, $rows);

        $out = [];
        foreach ($rows as $r) {
            $creator = $creators[(int) $r->creator_id] ?? null;
            $isAssignment = $r->type === self::TYPE_ASSIGNMENT;
            $ctx = $isAssignment ? ($assignments[(int) $r->assignment_id] ?? null) : null;
            $preview = $previews[$r->type.':'.$r->id] ?? ['preview' => null, 'preview_kind' => 'text'];

            $out[] = [
                'id' => $r->ulid,
                'type' => $r->type,
                'attributes' => [
                    'creator' => [
                        'id' => $creator?->ulid,
                        'display_name' => $creator?->display_name,
                        'is_erased' => $creator?->isErased() ?? false,
                        'avatar_url' => ContactMediaUrl::resolve($creator?->avatar_path),
                    ],
                    'brand' => $ctx['brand'] ?? null,
                    'campaign' => $ctx['campaign'] ?? null,
                    'assignment_id' => $ctx['assignment_id'] ?? null,
                    'last_message_at' => $this->iso($r->last_at),
                    'preview' => $preview['preview'],
                    'preview_kind' => $preview['preview_kind'],
                    'unread_count' => (int) $r->unread,
                    'owner' => $r->owner_id !== null ? ($owners[(int) $r->owner_id] ?? null) : null,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<\stdClass>  $rows
     * @return array<int, array{brand: array<string, mixed>|null, campaign: array<string, mixed>, assignment_id: string}>
     */
    private function assignmentContext(Agency $agency, array $rows): array
    {
        $ids = array_values(array_filter(array_map(fn ($r): ?int => $r->assignment_id === null ? null : (int) $r->assignment_id, $rows)));
        if ($ids === []) {
            return [];
        }

        $rowsById = DB::table('campaign_assignments as a')
            ->join('campaigns as c', 'c.id', '=', 'a.campaign_id')
            ->leftJoin('brands as b', 'b.id', '=', 'c.brand_id')
            ->where('a.agency_id', $agency->id)
            ->whereIn('a.id', $ids)
            ->get(['a.id as aid', 'a.ulid as a_ulid', 'c.ulid as c_ulid', 'c.name as c_name', 'b.ulid as b_ulid', 'b.name as b_name', 'b.color as b_color']);

        $out = [];
        foreach ($rowsById as $x) {
            $out[(int) $x->aid] = [
                'assignment_id' => $x->a_ulid,
                'campaign' => ['id' => $x->c_ulid, 'name' => $x->c_name],
                'brand' => $x->b_ulid === null ? null : ['id' => $x->b_ulid, 'name' => $x->b_name, 'color' => $x->b_color],
            ];
        }

        return $out;
    }

    /**
     * The last HUMAN message per thread (system lines never). Two queries for the whole page.
     *
     * @param  list<\stdClass>  $rows
     * @return array<string, array{preview: string|null, preview_kind: string}>
     */
    private function previews(array $rows): array
    {
        $out = [];
        foreach ([self::TYPE_RELATIONSHIP => RelationshipMessage::class, self::TYPE_ASSIGNMENT => Message::class] as $type => $model) {
            $threadIds = array_values(array_map(fn ($r): int => (int) $r->id, array_filter($rows, fn ($r): bool => $r->type === $type)));
            if ($threadIds === []) {
                continue;
            }

            // AH-119 B2: the newest human message INCLUDING a deleted one, so a deleted last message
            // reads as `preview_kind: deleted` (preview null) and never falls back to older text.
            $latest = $model::query()->withTrashed()->selectRaw('max(id)')->whereIn('thread_id', $threadIds)->groupBy('thread_id');
            if ($type === self::TYPE_ASSIGNMENT) {
                $latest->where('kind', '!=', MessageKind::System->value);
            }

            foreach ($model::query()->withTrashed()->whereIn('id', $latest)->get(['id', 'thread_id', 'body', 'attachments', 'deleted_at']) as $m) {
                if ($m->deleted_at !== null) {
                    $out[$type.':'.$m->thread_id] = ['preview' => null, 'preview_kind' => 'deleted'];

                    continue;
                }

                $body = trim((string) $m->body);
                $hasFiles = is_array($m->attachments) && $m->attachments !== [];
                $out[$type.':'.$m->thread_id] = $body === '' && $hasFiles
                    ? ['preview' => null, 'preview_kind' => 'attachment']
                    : ['preview' => mb_substr($body, 0, self::PREVIEW_LENGTH), 'preview_kind' => 'text'];
            }
        }

        return $out;
    }

    /**
     * The owner of each row, shown only when that user is an ACTIVE member of this agency (an inviter
     * who was a platform admin, or who has left, reads as unowned).
     *
     * @param  list<\stdClass>  $rows
     * @return array<int, array{id: string, name: string}>
     */
    private function owners(Agency $agency, array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($r): ?int => $r->owner_id === null ? null : (int) $r->owner_id, $rows))));
        if ($ids === []) {
            return [];
        }

        $active = AgencyMembership::query()->where('agency_id', $agency->id)->whereIn('user_id', $ids)
            ->whereNotNull('accepted_at')->whereNull('deleted_at')->pluck('user_id')->all();

        $out = [];
        foreach (User::query()->whereIn('id', $active)->get(['id', 'ulid', 'name']) as $u) {
            $out[$u->id] = ['id' => $u->ulid, 'name' => $u->name];
        }

        return $out;
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value, 'UTC')->toIso8601String();
    }
}
