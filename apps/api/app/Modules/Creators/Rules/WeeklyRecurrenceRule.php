<?php

declare(strict_types=1);

namespace App\Modules\Creators\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use InvalidArgumentException;
use RRule\RRule;

/**
 * Validates that a submitted `recurrence_rule` stays inside the Sprint 5
 * weekly ceiling (D-a3, plan-pause Q1 = allow INTERVAL):
 *
 *   ALLOWED:  FREQ=WEEKLY (required)
 *           + INTERVAL (optional, "every N weeks" — biweekly is a real
 *             market pattern; locking INTERVAL=1 would push those creators
 *             back to manual blocks)
 *           + BYDAY    (optional, plain weekday codes only — MO/TU/.../SU)
 *           + UNTIL    (optional end date)
 *
 *   REJECTED: any other FREQ (DAILY/MONTHLY/YEARLY/...), BYMONTHDAY,
 *             BYMONTH, BYSETPOS, COUNT, numeric-prefixed BYDAY (e.g. "2MO"
 *             = monthly "2nd Monday"), an embedded DTSTART (dtstart comes
 *             from the block's starts_at, never the rule), or any part
 *             outside the allowlist.
 *
 * The library parses full RFC 5545 RRULE; the weekly ceiling is OUR
 * constraint, enforced here at validation against the RAW submitted parts
 * (so a `FREQ=DAILY` can never slip through into storage / expansion).
 * After the allowlist check we additionally hand the rule to the library
 * to confirm it is actually parseable.
 */
final class WeeklyRecurrenceRule implements ValidationRule
{
    /**
     * The only RRULE parts a creator may submit. Anything else fails.
     *
     * @var list<string>
     */
    private const array ALLOWED_PARTS = ['FREQ', 'INTERVAL', 'BYDAY', 'UNTIL'];

    /**
     * Plain weekday codes — no numeric prefix (a prefix like "2MO" is a
     * monthly "nth weekday" pattern, outside the weekly ceiling).
     *
     * @var list<string>
     */
    private const array WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The recurrence rule must be a non-empty RRULE string.');

            return;
        }

        $parts = $this->parseParts($value);
        if ($parts === null) {
            $fail('The recurrence rule is not a valid RRULE string.');

            return;
        }

        $unsupported = array_diff(array_keys($parts), self::ALLOWED_PARTS);
        if ($unsupported !== []) {
            $fail(sprintf(
                'The recurrence rule only supports weekly rules (FREQ=WEEKLY with optional INTERVAL, BYDAY, UNTIL). Unsupported part(s): %s.',
                implode(', ', $unsupported),
            ));

            return;
        }

        if (($parts['FREQ'] ?? null) !== 'WEEKLY') {
            $fail('The recurrence rule must be FREQ=WEEKLY (daily/monthly/custom recurrence is not supported).');

            return;
        }

        if (isset($parts['INTERVAL']) && ! $this->isPositiveInteger($parts['INTERVAL'])) {
            $fail('The recurrence INTERVAL must be a positive integer.');

            return;
        }

        if (isset($parts['BYDAY']) && ! $this->isPlainWeekdayList($parts['BYDAY'])) {
            $fail('The recurrence BYDAY must be plain weekday codes (MO, TU, WE, TH, FR, SA, SU).');

            return;
        }

        // Final belt-and-suspenders: the library must accept it as a
        // parseable rule. A fixed dtstart lets UNTIL validate without
        // depending on the block's own starts_at here.
        try {
            new RRule(array_merge($parts, ['DTSTART' => '2026-01-05']));
        } catch (InvalidArgumentException) {
            $fail('The recurrence rule is not a valid RRULE string.');
        }
    }

    /**
     * Split a `KEY=VALUE;KEY=VALUE` RRULE body into an upper-cased part
     * map. Returns null when the shape is malformed.
     *
     * @return array<string, string>|null
     */
    private function parseParts(string $rule): ?array
    {
        $parts = [];

        foreach (explode(';', trim($rule)) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (! str_contains($segment, '=')) {
                return null;
            }

            [$key, $val] = explode('=', $segment, 2);
            $key = strtoupper(trim($key));
            $val = trim($val);

            if ($key === '' || $val === '') {
                return null;
            }

            $parts[$key] = strtoupper($val);
        }

        return $parts === [] ? null : $parts;
    }

    private function isPositiveInteger(string $value): bool
    {
        return ctype_digit($value) && (int) $value >= 1;
    }

    private function isPlainWeekdayList(string $value): bool
    {
        foreach (explode(',', $value) as $day) {
            if (! in_array($day, self::WEEKDAYS, true)) {
                return false;
            }
        }

        return true;
    }
}
