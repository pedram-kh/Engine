<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Files;

use App\Modules\Erasure\Selection\Selections;
use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Decides which objects the erasure deletes (PII map §5), one named key at a
 * time:
 *
 * - the creator's avatar and cover, and every portfolio source and thumbnail
 *   (soft-deleted items included), each only if it sits under the creator's OWN
 *   prefix `creators/{ulid}/`;
 * - everything still stored under the two creator-exclusive prefixes
 *   `creators/{ulid}/avatar/` and `creators/{ulid}/portfolio/` that no row
 *   points at any more (a replaced avatar leaves its old object behind);
 * - the attachments of messages the subject SENT, each only if it sits under
 *   `messages/{thread ulid}/` or `relationship-messages/{thread ulid}/` of the
 *   thread the message is in.
 *
 * Never deleted here: `creators/{ulid}/drafts/` (kept, decision D-C), an agency
 * member's attachment in the same thread (a different message), and any key
 * outside the prefixes above, which is counted and skipped, not trusted.
 * There is no prefix delete anywhere: a shared prefix holds the agency's files.
 */
final class FileKeyCollector
{
    private const CLASSES = ['avatar', 'cover', 'portfolio', 'portfolio_thumbnail', 'unreferenced', 'message_attachment', 'relationship_attachment'];

    public function __construct(private readonly ObjectStore $store) {}

    public function collect(SubjectSet $subject, ?ConnectionInterface $db = null): FileKeys
    {
        $db ??= DB::connection();
        $own = "creators/{$subject->creatorUlid}/";

        /** @var array<string, string> $found key => class */
        $found = [];
        $skipped = 0;
        $add = function (mixed $key, string $class, string $prefix) use (&$found, &$skipped): void {
            if (! is_string($key) || $key === '') {
                return;
            }
            if (! str_starts_with($key, $prefix) || str_contains($key, '..') || str_contains($key, "\0") || str_contains($key, '//')) {
                $skipped++;

                return;
            }
            $found[$key] ??= $class;
        };

        $creator = $db->table('creators')->where('id', $subject->creatorId)->first(['avatar_path', 'cover_path']);
        if ($creator instanceof stdClass) {
            $add($creator->avatar_path, 'avatar', $own.'avatar/');
            $add($creator->cover_path, 'cover', $own);
        }

        if ($subject->portfolioItemIds !== []) {
            foreach ($db->table('creator_portfolio_items')->whereIn('id', $subject->portfolioItemIds)->get(['s3_path', 'thumbnail_path']) as $item) {
                $add($item->s3_path, 'portfolio', $own.'portfolio/');
                $add($item->thumbnail_path, 'portfolio_thumbnail', $own.'portfolio/');
            }
        }

        foreach (['avatar', 'portfolio'] as $namespace) {
            foreach ($this->store->list($own.$namespace.'/') as $key) {
                $add($key, 'unreferenced', $own.$namespace.'/');
            }
        }

        $this->addMessageAttachments($subject, $db, $add, 'messages.attachments', 'message_threads', 'messages', 'message_attachment');
        $this->addMessageAttachments($subject, $db, $add, 'relationship_messages.attachments', 'relationship_threads', 'relationship-messages', 'relationship_attachment');

        ksort($found);
        $byClass = array_fill_keys(self::CLASSES, 0);
        foreach ($found as $class) {
            $byClass[$class]++;
        }

        return new FileKeys(array_keys($found), $byClass, $skipped);
    }

    /**
     * @param  callable(mixed, string, string): void  $add
     */
    private function addMessageAttachments(SubjectSet $subject, ConnectionInterface $db, callable $add, string $selectionId, string $threadTable, string $prefix, string $class): void
    {
        $selection = null;
        foreach (Selections::all() as $candidate) {
            if ($candidate->id === $selectionId) {
                $selection = $candidate;
            }
        }
        if ($selection === null) {
            return;
        }

        $rows = [];
        foreach ($selection->builder($subject, $db)->get() as $row) {
            if ($row instanceof stdClass) {
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            return;
        }

        $ulids = [];
        foreach ($db->table($threadTable)->whereIn('id', array_values(array_unique(array_map(fn (stdClass $r): mixed => $r->thread_id, $rows))))->get(['id', 'ulid']) as $thread) {
            $ulids[(int) $thread->id] = (string) $thread->ulid;
        }

        foreach ($rows as $row) {
            $threadUlid = $ulids[(int) $row->thread_id] ?? null;
            $raw = $row->attachments ?? null;
            $items = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
            foreach (is_array($items) ? $items : [] as $item) {
                $key = is_array($item) ? ($item['s3_path'] ?? null) : null;
                $add($key, $class, $threadUlid === null ? "{$prefix}/\0" : "{$prefix}/{$threadUlid}/");
            }
        }
    }
}
