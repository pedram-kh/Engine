<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Enums\MessageSenderRole;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AH-119 B3 — the agency message search over BOTH message tables.
 *
 *  - Tenancy: every source joins its thread table and filters `t.agency_id = ?` explicitly (a raw query
 *    builder: no global scope applies, and the GIN index cannot enforce tenancy).
 *  - What matches: the CURRENT `body` only. A deleted message has `deleted_at` set (excluded) and a null
 *    body; an edited one matches its current text, never `original_body` (this class never selects it).
 *    System lines never match.
 *  - Postgres: `to_tsvector('simple', coalesce(body, '')) @@ to_tsquery('simple', 'a:* & b:*')`, ranked
 *    with `ts_rank`, newest first on ties. The expression is the migration's index expression exactly.
 *    Other drivers (SQLite in tests): one `LIKE ... ESCAPE '\'` per term with escaped wildcards (substring).
 *  - Terms: lower-cased, split on anything that is not a letter or digit, terms under 2 characters
 *    dropped, at most 8 terms of at most 40 characters. Only letters and digits ever reach `to_tsquery`.
 *  - Paging: an opaque cursor carries an offset (a float rank is no stable keyset); at most
 *    {@see self::MAX_OFFSET} + {@see self::PER_PAGE} results are reachable.
 *  - Snippet: plain text of at most {@see self::SNIPPET_LENGTH} characters around the first matching term
 *    (no markup, built in PHP so both drivers agree).
 *  - Result rows are the inbox rows of {@see MessageInboxQuery} plus the five search attributes; an
 *    erased creator's messages stay findable and carry `sender_is_erased` (Q5).
 */
final class MessageSearch
{
    public const PER_PAGE = 30;

    public const MIN_TERM_LENGTH = 2;

    public const MAX_TERMS = 8;

    public const MAX_TERM_LENGTH = 40;

    public const SNIPPET_LENGTH = 160;

    public const MAX_OFFSET = 300;

    private const SNIPPET_LEAD = 40;

    public function __construct(private readonly MessageInboxQuery $inbox) {}

    /**
     * @return array{rows: list<array<string, mixed>>, meta: array{next_cursor: string|null, per_page: int}}
     */
    public function run(Agency $agency, User $viewer, string $query, ?string $cursor): array
    {
        $terms = $this->terms($query);
        $empty = ['rows' => [], 'meta' => ['next_cursor' => null, 'per_page' => self::PER_PAGE]];
        if ($terms === []) {
            return $empty;
        }

        $offset = $this->offset($cursor);
        $limit = $offset + self::PER_PAGE + 1;

        $hits = array_merge(
            $this->hits('messages', 'message_threads', MessageInboxQuery::TYPE_ASSIGNMENT, $agency, $terms, $limit),
            $this->hits('relationship_messages', 'relationship_threads', MessageInboxQuery::TYPE_RELATIONSHIP, $agency, $terms, $limit),
        );
        usort($hits, static fn (array $a, array $b): int => [$b['rank'], $b['created_at'], $b['id'], $b['type']] <=> [$a['rank'], $a['created_at'], $a['id'], $a['type']]);

        $page = array_slice($hits, $offset, self::PER_PAGE + 1);
        $hasMore = count($page) > self::PER_PAGE && $offset + self::PER_PAGE <= self::MAX_OFFSET;
        $page = array_slice($page, 0, self::PER_PAGE);

        $threadRows = $this->inbox->rowsForThreads(
            $agency,
            $viewer,
            array_values(array_map(static fn (array $h): int => $h['thread_id'], array_filter($page, static fn (array $h): bool => $h['type'] === MessageInboxQuery::TYPE_RELATIONSHIP))),
            array_values(array_map(static fn (array $h): int => $h['thread_id'], array_filter($page, static fn (array $h): bool => $h['type'] === MessageInboxQuery::TYPE_ASSIGNMENT))),
        );
        $erased = $this->erasedSenders($page);

        $rows = [];
        foreach ($page as $hit) {
            $row = $threadRows[$hit['type'].':'.$hit['thread_ulid']] ?? null;
            if ($row === null) {
                continue;
            }

            $row['attributes'] = [
                'message_ulid' => $hit['ulid'],
                'snippet' => $this->snippet($hit['body'], $terms),
                'sent_at' => Carbon::parse($hit['created_at'], 'UTC')->toIso8601String(),
                'sender_role' => $hit['sender_role'],
                'sender_is_erased' => $hit['sender_role'] === MessageSenderRole::Creator->value && isset($erased[$hit['sender_user_id']]),
                ...$row['attributes'],
            ];
            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'meta' => [
                'next_cursor' => $hasMore ? base64_encode((string) json_encode(['o' => $offset + self::PER_PAGE])) : null,
                'per_page' => self::PER_PAGE,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function terms(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
        $terms = [];
        foreach ($parts === false ? [] : $parts as $part) {
            $part = mb_substr($part, 0, self::MAX_TERM_LENGTH);
            if (mb_strlen($part) >= self::MIN_TERM_LENGTH && ! in_array($part, $terms, true)) {
                $terms[] = $part;
            }
            if (count($terms) >= self::MAX_TERMS) {
                break;
            }
        }

        return $terms;
    }

    /**
     * @param  list<string>  $terms
     * @return list<array{type: string, id: int, ulid: string, thread_id: int, thread_ulid: string, body: string, created_at: string, sender_role: string, sender_user_id: int|null, rank: float}>
     */
    private function hits(string $messages, string $threads, string $type, Agency $agency, array $terms, int $limit): array
    {
        $pg = DB::connection()->getDriverName() === 'pgsql';

        $q = DB::table("{$messages} as m")
            ->join("{$threads} as t", 't.id', '=', 'm.thread_id')
            ->where('t.agency_id', $agency->id)
            ->whereNull('m.deleted_at')
            ->whereNotNull('m.body');

        if ($type === MessageInboxQuery::TYPE_ASSIGNMENT) {
            $q->where('m.kind', '!=', MessageKind::System->value);
        }

        if ($pg) {
            $tsquery = implode(' & ', array_map(static fn (string $t): string => $t.':*', $terms));
            $vector = "to_tsvector('simple', coalesce(m.body, ''))";
            $q->whereRaw("{$vector} @@ to_tsquery('simple', ?)", [$tsquery])
                ->selectRaw("m.id, m.ulid, m.thread_id, t.ulid as thread_ulid, m.body, m.created_at, m.sender_role, m.sender_user_id, ts_rank({$vector}, to_tsquery('simple', ?)) as rank", [$tsquery])
                ->orderByRaw("ts_rank({$vector}, to_tsquery('simple', ?)) desc", [$tsquery]);
        } else {
            foreach ($terms as $term) {
                $q->whereRaw("lower(m.body) like ? escape '\\'", ['%'.$this->escapeLike($term).'%']);
            }
            $q->selectRaw('m.id, m.ulid, m.thread_id, t.ulid as thread_ulid, m.body, m.created_at, m.sender_role, m.sender_user_id, 0 as rank');
        }

        $out = [];
        foreach ($this->limited($q, $limit) as $r) {
            $out[] = [
                'type' => $type,
                'id' => (int) $r->id,
                'ulid' => (string) $r->ulid,
                'thread_id' => (int) $r->thread_id,
                'thread_ulid' => (string) $r->thread_ulid,
                'body' => (string) $r->body,
                'created_at' => (string) $r->created_at,
                'sender_role' => (string) $r->sender_role,
                'sender_user_id' => $r->sender_user_id === null ? null : (int) $r->sender_user_id,
                'rank' => (float) $r->rank,
            ];
        }

        return $out;
    }

    /**
     * @return iterable<\stdClass>
     */
    private function limited(Builder $q, int $limit): iterable
    {
        return $q->orderByDesc('m.created_at')->orderByDesc('m.id')->limit($limit)->get();
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    private function offset(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $decoded = json_decode((string) base64_decode($cursor, true), true);
        $offset = is_array($decoded) && is_int($decoded['o'] ?? null) ? $decoded['o'] : 0;

        return min(max($offset, 0), self::MAX_OFFSET);
    }

    /**
     * @param  list<string>  $terms
     */
    private function snippet(string $body, array $terms): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $body));
        $position = null;
        foreach ($terms as $term) {
            $at = mb_stripos($flat, $term);
            if ($at !== false && ($position === null || $at < $position)) {
                $position = $at;
            }
        }

        $start = $position === null ? 0 : max(0, $position - self::SNIPPET_LEAD);
        $text = mb_substr($flat, $start, self::SNIPPET_LENGTH);
        $text = ($start > 0 ? '…' : '').$text;

        return $start + self::SNIPPET_LENGTH < mb_strlen($flat) ? $text.'…' : $text;
    }

    /**
     * @param  list<array<string, mixed>>  $page
     * @return array<int, true> user ids of senders whose creator profile is erased
     */
    private function erasedSenders(array $page): array
    {
        $userIds = [];
        foreach ($page as $hit) {
            if ($hit['sender_role'] === MessageSenderRole::Creator->value && is_int($hit['sender_user_id'])) {
                $userIds[] = $hit['sender_user_id'];
            }
        }
        if ($userIds === []) {
            return [];
        }

        $out = [];
        foreach (Creator::query()->withTrashed()->whereIn('user_id', array_unique($userIds))->get(['id', 'user_id', 'erased_at']) as $creator) {
            if ($creator->isErased()) {
                $out[(int) $creator->user_id] = true;
            }
        }

        return $out;
    }
}
