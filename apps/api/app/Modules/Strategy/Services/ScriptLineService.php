<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Http\Resources\ScriptPresenter;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptBroll;
use App\Modules\Strategy\Models\WaveScriptLine;
use App\Modules\Strategy\Models\WaveScriptLineText;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;

/**
 * The lines of a script and their B-roll rows (PL-3.2b, rulings 7 and 8). Every line is its OWN save block with its OWN `revision`,
 * exactly like a hook: two writers on different lines never conflict; two on the same line get a 409 with the current line, never
 * a silent overwrite. The line block holds the spoken text in every market language, the CTA flag and the fields of its B-roll
 * rows; adding or deleting a row moves the line's revision too (the row set is part of the block). Reordering takes the COMPLETE id
 * list, writes only positions and moves no line's revision. EVERY write goes through {@see ScriptGuard::transaction()}.
 *
 * The line number (`position + 2`) and the B-roll code (`{line number}_{n}`) are never stored: {@see ScriptCodes}.
 */
final class ScriptLineService
{
    /** A new empty line at the end. At most thirty. */
    public function add(Brand $brand, Wave $wave, User $actor, string $scriptUlid): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $count = WaveScriptLine::query()->where('script_id', $script->id)->count();
            if ($count >= StrategyLimits::LINES_PER_SCRIPT) {
                throw StrategyRuleViolation::scriptLinesLimit();
            }

            $line = WaveScriptLine::query()->create(['agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'script_id' => $script->id, 'position' => $count]);
            ScriptGuard::touch($script, $actor);
            Audit::log(
                action: AuditAction::WaveScriptLineCreated,
                actor: $actor,
                subject: $script,
                // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically). Ids, the number, a position: never a text.
                metadata: ['wave' => $locked->ulid, 'market' => ScriptGuard::marketUlid($script), 'script' => $script->ulid, 'number' => $script->number, 'line' => $line->ulid, 'position' => $line->position],
                agencyId: $locked->agency_id,
            );

            return $script;
        });
    }

    /**
     * Save one line block. `$changes` holds only the keys the request sent: `texts` (language => text, null or blank removes the
     * row; the language must be English or one of the market's), `cta` (bool), `shot_type` and `visual_description` of the line itself, and `broll` (row ulid => `{shot_type?, description?}`).
     * A save that changes nothing writes nothing. A stale `$revision` with a real change is a 409; a row of the line that no longer
     * exists is a 409 `broll_gone`; a deleted line is the 404 "this line was deleted".
     *
     * @param  array{texts?: array<string, string|null>, cta?: bool, shot_type?: string|null, visual_description?: string|null, broll?: array<string, array{shot_type?: string|null, description?: string|null}>}  $changes
     */
    public function update(Brand $brand, Wave $wave, User $actor, string $scriptUlid, string $lineUlid, array $changes, int $revision): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $lineUlid, $changes, $revision): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $line = $this->lockedLine($script, $lineUlid);

            $allowed = ScriptLanguages::allOf($script->market_id);
            foreach (array_keys($changes['texts'] ?? []) as $language) {
                if (! in_array((string) $language, $allowed, true)) {
                    throw StrategyRuleViolation::scriptLanguageNotInMarket((string) $language);
                }
            }
            $rows = WaveScriptBroll::query()->where('line_id', $line->id)->get()->keyBy('ulid');
            foreach (array_keys($changes['broll'] ?? []) as $ulid) {
                if (! $rows->has((string) $ulid)) {
                    throw StrategyRuleViolation::scriptBrollGone(ScriptPresenter::lineFor($script, $line));
                }
            }

            $existing = WaveScriptLineText::query()->where('line_id', $line->id)->get()->keyBy('language');
            $fields = [];
            $bodies = [];
            foreach ($changes['texts'] ?? [] as $language => $body) {
                if ($body !== $existing->get((string) $language)?->body) {
                    $fields[] = 'text:'.$language;
                    $bodies[(string) $language] = $body;
                }
            }
            $cta = null;
            if (array_key_exists('cta', $changes) && $changes['cta'] !== $line->cta) {
                $fields[] = 'cta';
                $cta = $changes['cta'];
            }
            $own = [];
            foreach (['shot_type', 'visual_description'] as $field) {
                if (array_key_exists($field, $changes) && $changes[$field] !== $line->{$field}) {
                    $fields[] = $field;
                    $own[$field] = $changes[$field];
                }
            }
            $rowChanges = [];
            foreach ($changes['broll'] ?? [] as $ulid => $sent) {
                $row = $rows->get((string) $ulid);
                foreach (['shot_type', 'description'] as $field) {
                    if ($row !== null && array_key_exists($field, $sent) && $sent[$field] !== $row->{$field}) {
                        $rowChanges[(string) $ulid][$field] = $sent[$field];
                        $fields[] = 'broll_'.$field;
                    }
                }
            }
            if ($fields === []) {
                return $script;
            }
            if ($revision !== $line->revision) {
                throw StrategyRuleViolation::scriptConflict('line', ScriptPresenter::lineFor($script, $line));
            }

            foreach ($bodies as $language => $body) {
                $text = $existing->get($language);
                if ($body === null) {
                    $text?->delete();
                } elseif ($text === null) {
                    WaveScriptLineText::query()->create([
                        'agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'script_id' => $script->id, 'line_id' => $line->id,
                        'language' => $language, 'body' => $body, 'origin' => 'manual',
                    ]);
                } else {
                    $text->forceFill(['body' => $body])->save();
                }
            }
            if ($cta !== null) {
                $line->cta = $cta;
            }
            foreach ($own as $field => $value) {
                $line->{$field} = $value;
            }
            foreach ($rowChanges as $ulid => $values) {
                $rows->get($ulid)?->forceFill($values)->save();
            }
            $line->revision++;
            $line->save();
            ScriptGuard::touch($script, $actor);
            Audit::log(
                action: AuditAction::WaveScriptLineUpdated,
                actor: $actor,
                subject: $script,
                // Field NAMES only (`text:sk`, `cta`, `shot_type`, `visual_description`, `broll_shot_type`, `broll_description`): never the text.
                metadata: ['wave' => $locked->ulid, 'market' => ScriptGuard::marketUlid($script), 'script' => $script->ulid, 'number' => $script->number, 'line' => $line->ulid, 'fields' => array_values(array_unique($fields))],
                agencyId: $locked->agency_id,
            );

            return $script;
        });
    }

    /** Copy a line (texts in every language, CTA flag, B-roll rows, images) right after the original. Within every limit. */
    public function duplicate(Brand $brand, Wave $wave, User $actor, string $scriptUlid, string $lineUlid): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $lineUlid): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $line = $this->lockedLine($script, $lineUlid);
            if (WaveScriptLine::query()->where('script_id', $script->id)->count() >= StrategyLimits::LINES_PER_SCRIPT) {
                throw StrategyRuleViolation::scriptLinesLimit();
            }
            $rows = WaveScriptBroll::query()->where('line_id', $line->id)->orderBy('position')->orderBy('id')->get();
            if (WaveScriptBroll::query()->where('script_id', $script->id)->count() + $rows->count() > StrategyLimits::BROLL_SAFETY_CEILING) {
                throw StrategyRuleViolation::scriptBrollCeiling();
            }

            WaveScriptLine::query()->where('script_id', $script->id)->where('position', '>', $line->position)->increment('position');
            $copy = WaveScriptLine::query()->create([
                'agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'script_id' => $script->id, 'position' => $line->position + 1, 'cta' => $line->cta,
                'shot_type' => $line->shot_type, 'visual_description' => $line->visual_description,
            ]);
            foreach (WaveScriptLineText::query()->where('line_id', $line->id)->get() as $text) {
                WaveScriptLineText::query()->create([
                    'agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'script_id' => $script->id, 'line_id' => $copy->id,
                    'language' => $text->language, 'body' => $text->body, 'origin' => $text->origin,
                ]);
            }
            foreach ($rows as $row) {
                WaveScriptBroll::query()->create([
                    'agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'script_id' => $script->id, 'line_id' => $copy->id,
                    'position' => $row->position, 'shot_type' => $row->shot_type, 'description' => $row->description,
                ]);
            }
            ScriptImages::copyBlock($script, null, $line->id, null, $copy->id, $actor->id);
            ScriptGuard::touch($script, $actor);
            Audit::log(
                action: AuditAction::WaveScriptLineDuplicated,
                actor: $actor,
                subject: $script,
                metadata: ['wave' => $locked->ulid, 'market' => ScriptGuard::marketUlid($script), 'script' => $script->ulid, 'number' => $script->number, 'line' => $copy->ulid, 'from' => $line->ulid],
                agencyId: $locked->agency_id,
            );

            return $script;
        });
    }

    /** Delete a line with its texts, B-roll rows and images (the stored objects first) and close the gap in the order. */
    public function delete(Brand $brand, Wave $wave, User $actor, string $scriptUlid, string $lineUlid): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $lineUlid): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $line = $this->lockedLine($script, $lineUlid);

            // Objects first: a failed delete throws here, before a row is touched, and the line stays.
            $images = ScriptImages::purgeBlock($script->id, null, $line->id);
            $broll = WaveScriptBroll::query()->where('line_id', $line->id)->delete();
            WaveScriptLineText::query()->where('line_id', $line->id)->delete();
            $line->delete();
            WaveScriptLine::query()->where('script_id', $script->id)->where('position', '>', $line->position)->decrement('position');
            ScriptGuard::touch($script, $actor);
            Audit::log(
                action: AuditAction::WaveScriptLineDeleted,
                actor: $actor,
                subject: $script,
                metadata: ['wave' => $locked->ulid, 'market' => ScriptGuard::marketUlid($script), 'script' => $script->ulid, 'number' => $script->number, 'line' => $line->ulid, 'broll_rows' => $broll, 'images' => $images],
                agencyId: $locked->agency_id,
            );

            return $script;
        });
    }

    /**
     * Reorder the lines. `$ids` must be exactly the lines that exist now (the complete list), otherwise `order_mismatch` with the
     * current order and nothing is written. No line's revision moves; the script's does. The B-roll codes follow their lines.
     *
     * @param  list<string>  $ids
     */
    public function reorder(Brand $brand, Wave $wave, User $actor, string $scriptUlid, array $ids): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $ids): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $lines = WaveScriptLine::query()->where('script_id', $script->id)->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $current = array_values($lines->map(static fn ($m): string => (string) $m->ulid)->all());
            if (! ScriptOrder::sameSet($current, $ids)) {
                throw StrategyRuleViolation::scriptOrderMismatch('lines', $current);
            }
            $byUlid = $lines->keyBy('ulid');
            foreach ($ids as $position => $ulid) {
                $line = $byUlid->get($ulid);
                if ($line !== null && $line->position !== $position) {
                    // `position` only: the line's revision is deliberately NOT moved (ruling 7).
                    WaveScriptLine::query()->whereKey($line->id)->update(['position' => $position]);
                }
            }
            ScriptGuard::touch($script, $actor);
            Audit::log(
                action: AuditAction::WaveScriptLineReordered,
                actor: $actor,
                subject: $script,
                metadata: ['wave' => $locked->ulid, 'market' => ScriptGuard::marketUlid($script), 'script' => $script->ulid, 'number' => $script->number, 'count' => count($ids)],
                agencyId: $locked->agency_id,
            );

            return $script;
        });
    }

    /** A new empty B-roll row at the end of the line. At most twelve in the whole script. */
    public function brollAdd(Brand $brand, Wave $wave, User $actor, string $scriptUlid, string $lineUlid): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $lineUlid): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $line = $this->lockedLine($script, $lineUlid);
            if (WaveScriptBroll::query()->where('script_id', $script->id)->count() >= StrategyLimits::BROLL_SAFETY_CEILING) {
                throw StrategyRuleViolation::scriptBrollCeiling();
            }

            $row = WaveScriptBroll::query()->create([
                'agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'script_id' => $script->id, 'line_id' => $line->id,
                'position' => WaveScriptBroll::query()->where('line_id', $line->id)->count(),
            ]);
            $line->revision++;
            $line->save();
            ScriptGuard::touch($script, $actor);
            Audit::log(
                action: AuditAction::WaveScriptBrollCreated,
                actor: $actor,
                subject: $script,
                metadata: ['wave' => $locked->ulid, 'market' => ScriptGuard::marketUlid($script), 'script' => $script->ulid, 'number' => $script->number, 'line' => $line->ulid, 'broll' => $row->ulid],
                agencyId: $locked->agency_id,
            );

            return $script;
        });
    }

    /** Delete a B-roll row and close the gap in the line's order. The line's revision moves (the row set is part of the block). */
    public function brollDelete(Brand $brand, Wave $wave, User $actor, string $scriptUlid, string $lineUlid, string $brollUlid): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $lineUlid, $brollUlid): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $line = $this->lockedLine($script, $lineUlid);
            $row = WaveScriptBroll::query()->where('line_id', $line->id)->where('ulid', $brollUlid)->first();
            if ($row === null) {
                throw StrategyRuleViolation::conflict404();
            }

            $row->delete();
            WaveScriptBroll::query()->where('line_id', $line->id)->where('position', '>', $row->position)->decrement('position');
            $line->revision++;
            $line->save();
            ScriptGuard::touch($script, $actor);
            Audit::log(
                action: AuditAction::WaveScriptBrollDeleted,
                actor: $actor,
                subject: $script,
                metadata: ['wave' => $locked->ulid, 'market' => ScriptGuard::marketUlid($script), 'script' => $script->ulid, 'number' => $script->number, 'line' => $line->ulid, 'broll' => $row->ulid],
                agencyId: $locked->agency_id,
            );

            return $script;
        });
    }

    /**
     * Reorder the B-roll rows WITHIN one line (complete list, as for lines). The codes of that line renumber; no revision of the line
     * moves (position is not part of the block), the script's does.
     *
     * @param  list<string>  $ids
     */
    public function brollReorder(Brand $brand, Wave $wave, User $actor, string $scriptUlid, string $lineUlid, array $ids): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $lineUlid, $ids): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $line = $this->lockedLine($script, $lineUlid);
            $rows = WaveScriptBroll::query()->where('line_id', $line->id)->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $current = array_values($rows->map(static fn ($m): string => (string) $m->ulid)->all());
            if (! ScriptOrder::sameSet($current, $ids)) {
                throw StrategyRuleViolation::scriptOrderMismatch('broll', $current);
            }
            $byUlid = $rows->keyBy('ulid');
            foreach ($ids as $position => $ulid) {
                $row = $byUlid->get($ulid);
                if ($row !== null && $row->position !== $position) {
                    $row->forceFill(['position' => $position])->save();
                }
            }
            ScriptGuard::touch($script, $actor);
            Audit::log(
                action: AuditAction::WaveScriptBrollReordered,
                actor: $actor,
                subject: $script,
                metadata: ['wave' => $locked->ulid, 'market' => ScriptGuard::marketUlid($script), 'script' => $script->ulid, 'number' => $script->number, 'line' => $line->ulid, 'count' => count($ids)],
                agencyId: $locked->agency_id,
            );

            return $script;
        });
    }

    /** The line of the script by ulid, locked FOR UPDATE. A line that is gone is the "this line was deleted" answer. */
    private function lockedLine(WaveScript $script, string $ulid): WaveScriptLine
    {
        $line = WaveScriptLine::query()->where('script_id', $script->id)->where('ulid', $ulid)->lockForUpdate()->first();
        if ($line === null) {
            throw StrategyRuleViolation::scriptLineDeleted();
        }

        return $line;
    }
}
