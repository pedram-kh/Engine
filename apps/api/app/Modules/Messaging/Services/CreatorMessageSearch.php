<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageKind;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AH-115 L4 — the creator's message search over BOTH message tables, scoped by CREATOR.
 *
 * The creator variant of {@see MessageSearch}: the same term rules, the same body-only match, the same
 * GIN index expression and SQLite LIKE fallback, the same offset cursor and snippet. The scope differs:
 * a relationship hit joins `relationship_threads` on `t.creator_id = ?` and an assignment hit joins
 * `campaign_assignments` on `a.creator_id = ?`, both explicit (raw builder: no global scope applies), so a
 * thread of another creator never matches. A deleted message (`deleted_at`) and a system line never match,
 * and an edit matches its current text only (`original_body` is never selected).
 *
 * Result rows are the inbox rows of {@see CreatorMessageInboxQuery} plus `message_ulid`, `snippet`,
 * `sent_at`, `sender_role` and `is_own` (the signed-in creator wrote the match).
 */
final class CreatorMessageSearch
{
    private const SNIPPET_LEAD = 40;

    public function __construct(
        private readonly CreatorMessageInboxQuery $inbox,
        private readonly MessageSearch $terms,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, meta: array{next_cursor: string|null, per_page: int}}
     */
    public function run(Creator $creator, User $viewer, string $query, ?string $cursor): array
    {
        $terms = $this->terms->terms($query);
        $empty = ['rows' => [], 'meta' => ['next_cursor' => null, 'per_page' => MessageSearch::PER_PAGE]];
        if ($terms === []) {
            return $empty;
        }

        $offset = $this->offset($cursor);
        $limit = $offset + MessageSearch::PER_PAGE + 1;

        $hits = array_merge(
            $this->hits(MessageInboxQuery::TYPE_ASSIGNMENT, $creator, $terms, $limit),
            $this->hits(MessageInboxQuery::TYPE_RELATIONSHIP, $creator, $terms, $limit),
        );
        usort($hits, static fn (array $a, array $b): int => [$b['rank'], $b['created_at'], $b['id'], $b['type']] <=> [$a['rank'], $a['created_at'], $a['id'], $a['type']]);

        $page = array_slice($hits, $offset, MessageSearch::PER_PAGE + 1);
        $hasMore = count($page) > MessageSearch::PER_PAGE && $offset + MessageSearch::PER_PAGE <= MessageSearch::MAX_OFFSET;
        $page = array_slice($page, 0, MessageSearch::PER_PAGE);

        $threadRows = $this->inbox->rowsForThreads(
            $creator,
            $viewer,
            array_values(array_map(static fn (array $h): int => $h['thread_id'], array_filter($page, static fn (array $h): bool => $h['type'] === MessageInboxQuery::TYPE_RELATIONSHIP))),
            array_values(array_map(static fn (array $h): int => $h['thread_id'], array_filter($page, static fn (array $h): bool => $h['type'] === MessageInboxQuery::TYPE_ASSIGNMENT))),
        );

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
                'is_own' => $hit['sender_user_id'] !== null && $hit['sender_user_id'] === $viewer->id,
                ...$row['attributes'],
            ];
            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'meta' => [
                'next_cursor' => $hasMore ? base64_encode((string) json_encode(['o' => $offset + MessageSearch::PER_PAGE])) : null,
                'per_page' => MessageSearch::PER_PAGE,
            ],
        ];
    }

    /**
     * @param  list<string>  $terms
     * @return list<array{type: string, id: int, ulid: string, thread_id: int, thread_ulid: string, body: string, created_at: string, sender_role: string, sender_user_id: int|null, rank: float}>
     */
    private function hits(string $type, Creator $creator, array $terms, int $limit): array
    {
        $pg = DB::connection()->getDriverName() === 'pgsql';

        if ($type === MessageInboxQuery::TYPE_ASSIGNMENT) {
            $q = DB::table('messages as m')
                ->join('message_threads as t', 't.id', '=', 'm.thread_id')
                ->join('campaign_assignments as a', 'a.id', '=', 't.assignment_id')
                ->where('a.creator_id', $creator->id)
                ->where('m.kind', '!=', MessageKind::System->value);
        } else {
            $q = DB::table('relationship_messages as m')
                ->join('relationship_threads as t', 't.id', '=', 'm.thread_id')
                ->where('t.creator_id', $creator->id);
        }
        $q->whereNull('m.deleted_at')->whereNotNull('m.body');

        $this->match($q, $terms, $pg);

        $out = [];
        foreach ($q->orderByDesc('m.created_at')->orderByDesc('m.id')->limit($limit)->get() as $r) {
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
     * @param  list<string>  $terms
     */
    private function match(Builder $q, array $terms, bool $pg): void
    {
        if ($pg) {
            $tsquery = implode(' & ', array_map(static fn (string $t): string => $t.':*', $terms));
            $vector = "to_tsvector('simple', coalesce(m.body, ''))";
            $q->whereRaw("{$vector} @@ to_tsquery('simple', ?)", [$tsquery])
                ->selectRaw("m.id, m.ulid, m.thread_id, t.ulid as thread_ulid, m.body, m.created_at, m.sender_role, m.sender_user_id, ts_rank({$vector}, to_tsquery('simple', ?)) as rank", [$tsquery])
                ->orderByRaw("ts_rank({$vector}, to_tsquery('simple', ?)) desc", [$tsquery]);

            return;
        }

        foreach ($terms as $term) {
            $q->whereRaw("lower(m.body) like ? escape '\\'", ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%']);
        }
        $q->selectRaw('m.id, m.ulid, m.thread_id, t.ulid as thread_ulid, m.body, m.created_at, m.sender_role, m.sender_user_id, 0 as rank');
    }

    private function offset(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $decoded = json_decode((string) base64_decode($cursor, true), true);
        $offset = is_array($decoded) && is_int($decoded['o'] ?? null) ? $decoded['o'] : 0;

        return min(max($offset, 0), MessageSearch::MAX_OFFSET);
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
        $text = mb_substr($flat, $start, MessageSearch::SNIPPET_LENGTH);
        $text = ($start > 0 ? '…' : '').$text;

        return $start + MessageSearch::SNIPPET_LENGTH < mb_strlen($flat) ? $text.'…' : $text;
    }
}
