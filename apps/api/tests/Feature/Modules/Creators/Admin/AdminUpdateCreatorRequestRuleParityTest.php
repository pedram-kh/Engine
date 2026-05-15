<?php

declare(strict_types=1);

use App\Modules\Creators\Http\Requests\AdminUpdateCreatorRequest;
use App\Modules\Creators\Http\Requests\UpdateProfileRequest;
use Illuminate\Validation\Rules\In;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Cross-layer contract verification per Sprint 3 § b (avatar-completeness
 * lesson): the admin per-field PATCH validation rules MUST match the
 * wizard form-request rules for the same fields. If the backend accepts
 * a value the wizard would reject (or vice versa), we've reintroduced
 * the seam mismatch the Chunk 3 saga taught us about.
 *
 * Source-inspection regression (#1): walks the 7 admin-editable fields
 * and asserts the rule strings are identical.
 */
it('AdminUpdateCreatorRequest rules match the wizard UpdateProfileRequest rules for each editable field', function (string $field): void {
    $adminRules = (new AdminUpdateCreatorRequest)->rules();
    $wizardRules = (new UpdateProfileRequest)->rules();

    expect($adminRules)->toHaveKey($field);
    expect($wizardRules)->toHaveKey($field);

    expect($adminRules[$field])->toEqual($wizardRules[$field]);
})->with([
    'display_name' => ['display_name'],
    'bio' => ['bio'],
    'country_code' => ['country_code'],
    'region' => ['region'],
    'primary_language' => ['primary_language'],
    'secondary_languages' => ['secondary_languages'],
]);

it('AdminUpdateCreatorRequest categories rules contain the same 16-enum check as the wizard', function (): void {
    $adminRules = (new AdminUpdateCreatorRequest)->rules();
    $wizardRules = (new UpdateProfileRequest)->rules();

    // categories array-level rules
    expect($adminRules['categories'])->toEqual($wizardRules['categories']);

    // categories.* enum check — the admin request uses Rule::in() while the
    // wizard uses an inline `in:` string. Both must resolve to the same
    // 16-category set. Compare by extracting the values from each.
    /** @var array<int, mixed> $adminStarRules */
    $adminStarRules = $adminRules['categories.*'];
    /** @var array<int, string> $wizardStarRules */
    $wizardStarRules = $wizardRules['categories.*'];

    $adminEnum = collect($adminStarRules)
        ->first(fn ($rule) => $rule instanceof In);
    $wizardEnum = collect($wizardStarRules)
        ->first(fn (string $rule) => str_starts_with($rule, 'in:'));

    expect($adminEnum)->not->toBeNull();
    expect($wizardEnum)->not->toBeNull();
    assert($adminEnum instanceof In);
    assert(is_string($wizardEnum));

    // Normalise to a sorted list of category slugs for comparison.
    /** @var array<int, string> $adminValues */
    $adminValues = (fn () => $this->values)->call($adminEnum);
    $adminSet = collect($adminValues)->sort()->values()->all();
    $wizardSet = collect(explode(',', substr($wizardEnum, 3)))->sort()->values()->all();

    expect($adminSet)->toEqual($wizardSet);
});

it('AdminUpdateCreatorRequest EDITABLE_FIELDS does not include application_status (Q-chunk-4-2 = (a))', function (): void {
    expect(AdminUpdateCreatorRequest::EDITABLE_FIELDS)
        ->not->toContain('application_status');
});

it('AdminUpdateCreatorRequest REASON_REQUIRED_FIELDS pins the sensitive-field set', function (): void {
    expect(AdminUpdateCreatorRequest::REASON_REQUIRED_FIELDS)
        ->toEqual(['bio', 'categories']);
});
