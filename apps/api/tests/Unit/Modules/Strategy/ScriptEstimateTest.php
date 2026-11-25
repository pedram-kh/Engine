<?php

declare(strict_types=1);

use App\Modules\Strategy\Services\ScriptEstimate;

// PL-3.2a fix 5: the estimate counts the longest spoken hook only, at 2.5 words per second, rounded up.

it('counts the longest hook, never the sum of the hooks', function (): void {
    $est = ScriptEstimate::of(['one two three', 'one two three four five six seven eight nine ten', 'one two']);

    expect($est)->toBe(['words' => 10, 'seconds' => 4]);
});

it('rounds up to a whole second and counts whitespace-separated words', function (): void {
    expect(ScriptEstimate::of(["one  two\nthree\tfour five six"]))->toBe(['words' => 6, 'seconds' => 3])
        ->and(ScriptEstimate::of(['one']))->toBe(['words' => 1, 'seconds' => 1]);
});

it('is zero with no spoken text', function (): void {
    expect(ScriptEstimate::of([]))->toBe(['words' => 0, 'seconds' => 0])
        ->and(ScriptEstimate::of(['', '   ']))->toBe(['words' => 0, 'seconds' => 0]);
});

it('adds the spoken lines (PL-3.2b) on top of the longest hook', function (): void {
    expect(ScriptEstimate::of(['a b c d', 'a b'], ['x y z', 'one two']))->toBe(['words' => 9, 'seconds' => 4]);
});
