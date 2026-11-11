<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Agencies\Models\Agency;
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
 * AH-115 L4 — the creator's unified inbox: ONE query over both thread tables, scoped by CREATOR.
 *
 * The creator variant of {@see MessageInboxQuery} (the agency query is not reused or changed). The same
 * rules, read for the creator side:
 *
 *  - Scope: the relationship leg is `relationship_threads.creator_id = ?`; the assignment leg is
 *    `campaign_assignments.creator_id = ?` joined to its thread. Both are explicit (a raw query builder:
 *    no global scope applies), so another creator's thread can never appear.
 *  - A thread surfaces only with a human (non-system) message that is not deleted.
 *  - Unread is per viewer, set-based (one grouped NOT EXISTS per table, joined), human messages only.
 *  - Order and cursor: `(last message time, type, id)` descending.
 *  - Per row: the agency (name and logo), the brand and campaign for an assignment thread (the tag is
 *    `Brand · Campaign`; a relationship thread is `Direct`), the preview and its kind, the time and the
 *    unread count. No facets, no owner, no brand colour: the creator side has none of them.
 */
final class CreatorMessageInboxQuery
{
    public const DEFAULT_PER_PAGE = 30;

    public const MAX_PER_PAGE = 100;

    private const PREVIEW_LENGTH = 200;

    /**
     * @param  array{per_page?: int, cursor?: string|null, unread?: bool, direct?: bool}  $params
     * @return array{rows: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function run(Creator $creator, User $viewer, array $params): array
    {
        $perPage = min(max((int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        $union = $this->threads($creator, $viewer);

        // The two creator filters: `direct` keeps only the relationship (Direct) threads, `unread`
        // only threads with an unread human message. They combine (AND). The unread pill in `meta`
        // is the unfiltered total, as on the agency side.
        $filtered = clone $union;
        if ((bool) ($params['direct'] ?? false)) {
            $filtered->where('inbox.type', MessageInboxQuery::TYPE_RELATIONSHIP);
        }
        if ((bool) ($params['unread'] ?? false)) {
            $filtered->where('inbox.unread', '>', 0);
        }

        $page = (clone $filtered)->select('inbox.*')
            ->orderByDesc('last_at')->orderByDesc('type')->orderByDesc('id');
        $this->applyCursor($page, $params['cursor'] ?? null);

        $fetched = $page->limit($perPage + 1)->get();
        $hasMore = $fetched->count() > $perPage;
        $rows = $fetched->take($perPage)->values();
        $last = $rows->last();

        return [
            'rows' => $this->present(array_values($rows->all())),
            'meta' => [
                'unread_threads_total' => (int) (clone $union)->where('inbox.unread', '>', 0)->count(),
                'next_cursor' => $hasMore && $last !== null
                    ? base64_encode((string) json_encode([$last->last_at, $last->type, (int) $last->id]))
                    : null,
            ],
        ];
    }

    /**
     * The inbox rows (the shape of {@see self::run()}'s `rows`) of the given threads, for the search.
     * Same scope, same unread and preview rules; a thread without a surfaceable human message yields
     * no row.
     *
     * @param  list<int>  $relationshipThreadIds
     * @param  list<int>  $assignmentThreadIds
     * @return array<string, array<string, mixed>> keyed `type:thread ulid`
     */
    public function rowsForThreads(Creator $creator, User $viewer, array $relationshipThreadIds, array $assignmentThreadIds): array
    {
        if ($relationshipThreadIds === [] && $assignmentThreadIds === []) {
            return [];
        }

        $q = (clone $this->threads($creator, $viewer))->select('inbox.*')
            ->where(function ($w) use ($relationshipThreadIds, $assignmentThreadIds): void {
                $w->where(fn ($x) => $x->where('inbox.type', MessageInboxQuery::TYPE_RELATIONSHIP)->whereIn('inbox.id', $relationshipThreadIds))
                    ->orWhere(fn ($x) => $x->where('inbox.type', MessageInboxQuery::TYPE_ASSIGNMENT)->whereIn('inbox.id', $assignmentThreadIds));
            });

        $out = [];
        foreach ($this->present(array_values($q->get()->all())) as $row) {
            $out[$row['type'].':'.$row['id']] = $row;
        }

        return $out;
    }

    private function threads(Creator $creator, User $viewer): Builder
    {
        // Both unread aggregates are scoped to THIS creator's threads: unscoped they would count every
        // message in the table on each request (EXPLAIN: a full scan of the message table).
        $relationshipUnread = DB::table('relationship_messages as m')
            ->selectRaw('m.thread_id, count(*) as unread')
            ->whereIn('m.thread_id', DB::table('relationship_threads')->select('id')->where('creator_id', $creator->id))
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
            ->leftJoinSub($relationshipUnread, 'u', 'u.thread_id', '=', 't.id')
            ->where('t.creator_id', $creator->id)
            ->whereExists($relationshipHuman)
            ->selectRaw(
                "'".MessageInboxQuery::TYPE_RELATIONSHIP."' as type, t.id as id, t.ulid as ulid, "
                .'coalesce(t.last_message_at, t.created_at) as last_at, t.agency_id as agency_id, '
                .'cast(null as bigint) as assignment_id, coalesce(u.unread, 0) as unread',
            );

        $assignmentUnread = DB::table('messages as m')
            ->selectRaw('m.thread_id, count(*) as unread')
            ->whereIn('m.thread_id', DB::table('message_threads as mt')
                ->join('campaign_assignments as ma', 'ma.id', '=', 'mt.assignment_id')
                ->where('ma.creator_id', $creator->id)->select('mt.id'))
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
            ->leftJoinSub($assignmentUnread, 'u', 'u.thread_id', '=', 't.id')
            ->where('a.creator_id', $creator->id)
            ->whereExists($assignmentHuman)
            ->selectRaw(
                "'".MessageInboxQuery::TYPE_ASSIGNMENT."' as type, t.id as id, t.ulid as ulid, "
                .'coalesce(t.last_message_at, t.created_at) as last_at, t.agency_id as agency_id, '
                .'a.id as assignment_id, coalesce(u.unread, 0) as unread',
            );

        return DB::query()->fromSub($relationship->unionAll($assignment), 'inbox');
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
     * Turn the page of raw rows into resources. A fixed number of queries whatever the page size.
     *
     * @param  list<\stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $agencies = Agency::query()->withTrashed()
            ->whereIn('id', array_values(array_unique(array_map(fn ($r): int => (int) $r->agency_id, $rows))))
            ->get(['id', 'ulid', 'name', 'logo_path'])->keyBy('id');

        $context = $this->assignmentContext($rows);
        $previews = $this->previews($rows);

        $out = [];
        foreach ($rows as $r) {
            $agency = $agencies[(int) $r->agency_id] ?? null;
            $isAssignment = $r->type === MessageInboxQuery::TYPE_ASSIGNMENT;
            $ctx = $isAssignment ? ($context[(int) $r->assignment_id] ?? null) : null;
            $preview = $previews[$r->type.':'.$r->id] ?? ['preview' => null, 'preview_kind' => 'text'];

            $out[] = [
                'id' => $r->ulid,
                'type' => $r->type,
                'attributes' => [
                    'agency' => [
                        'id' => $agency?->ulid,
                        'name' => $agency?->name,
                        'logo_url' => ContactMediaUrl::resolve($agency?->logo_path),
                    ],
                    'brand' => $ctx['brand'] ?? null,
                    'campaign' => $ctx['campaign'] ?? null,
                    'assignment_id' => $ctx['assignment_id'] ?? null,
                    'last_message_at' => $this->iso($r->last_at),
                    'preview' => $preview['preview'],
                    'preview_kind' => $preview['preview_kind'],
                    'unread_count' => (int) $r->unread,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<\stdClass>  $rows
     * @return array<int, array{brand: array<string, mixed>|null, campaign: array<string, mixed>, assignment_id: string}>
     */
    private function assignmentContext(array $rows): array
    {
        $ids = array_values(array_filter(array_map(fn ($r): ?int => $r->assignment_id === null ? null : (int) $r->assignment_id, $rows)));
        if ($ids === []) {
            return [];
        }

        $out = [];
        $found = DB::table('campaign_assignments as a')
            ->join('campaigns as c', 'c.id', '=', 'a.campaign_id')
            ->leftJoin('brands as b', 'b.id', '=', 'c.brand_id')
            ->whereIn('a.id', $ids)
            ->get(['a.id as aid', 'a.ulid as a_ulid', 'c.ulid as c_ulid', 'c.name as c_name', 'b.ulid as b_ulid', 'b.name as b_name']);
        foreach ($found as $x) {
            $out[(int) $x->aid] = [
                'assignment_id' => $x->a_ulid,
                'campaign' => ['id' => $x->c_ulid, 'name' => $x->c_name],
                'brand' => $x->b_ulid === null ? null : ['id' => $x->b_ulid, 'name' => $x->b_name],
            ];
        }

        return $out;
    }

    /**
     * The last HUMAN message per thread (system lines never). Deleted last message reads as `deleted`.
     *
     * @param  list<\stdClass>  $rows
     * @return array<string, array{preview: string|null, preview_kind: string}>
     */
    private function previews(array $rows): array
    {
        $out = [];
        foreach ([MessageInboxQuery::TYPE_RELATIONSHIP => RelationshipMessage::class, MessageInboxQuery::TYPE_ASSIGNMENT => Message::class] as $type => $model) {
            $threadIds = array_values(array_map(fn ($r): int => (int) $r->id, array_filter($rows, fn ($r): bool => $r->type === $type)));
            if ($threadIds === []) {
                continue;
            }

            $latest = $model::query()->withTrashed()->selectRaw('max(id)')->whereIn('thread_id', $threadIds)->groupBy('thread_id');
            if ($type === MessageInboxQuery::TYPE_ASSIGNMENT) {
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

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value, 'UTC')->toIso8601String();
    }
}
