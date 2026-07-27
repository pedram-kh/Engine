<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Requests\Concerns;

use App\Core\Enums\Locale;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Validation\Rule;

/**
 * The jobs-board listing contract (AH-054, D2 + D3), shared by the create and
 * Settings-edit requests so the two can never drift.
 *
 * Field shapes (D2):
 *   - `listing_duration` / `listing_fee` — free text, the AH-034 `fee_per`
 *     precedent (agency-authored, untranslated, no enum). `listing_fee` is
 *     display copy only; the binding offer stays per-assignment.
 *   - `listing_languages` — the 24-EU set. Per the three-set locale
 *     architecture, a campaign's PRODUCTION language follows the operating
 *     markets ({@see Locale} cases), not the creator-side world set.
 *   - `listing_regions` — ISO-3166-1 alpha-2 codes, uppercase-normalised
 *     before validation. Validated as `size:2` + an uppercase-letters pattern,
 *     matching every other country field on the platform (`country_code` is a
 *     bare `size:2` everywhere): there is no backend ISO-3166 registry today,
 *     and the FE `countries.ts` picker is the practical gate. Building that
 *     registry (with a TS↔PHP parity test, the {@see Locale} shape) is logged
 *     in docs/tech-debt.md.
 *   - Both arrays are `distinct` and bounded, so a client cannot post an
 *     unbounded jsonb blob.
 *
 * The D3 floor is the OTHER half: a campaign may carry any subset of these
 * fields while unlisted, but the write that leaves it LISTED must leave it
 * complete. {@see self::missingListingFloorFields()} is the single predicate
 * behind that rule; the SPA mirrors this same field list so the 422 is a
 * backstop, never the user's first notice.
 */
trait ValidatesJobsBoardListing
{
    /**
     * The D3 floor — what a listed job must carry. `description` is included
     * deliberately: it is the body copy of the job card, and AH-032 already
     * made it the field that absorbs deliverables and usage terms.
     *
     * Mirrored FE-side by `LISTING_FLOOR_FIELDS` in
     * `apps/main/src/modules/campaigns/listingFloor.ts`, pinned against this
     * list by a source-scanning parity spec.
     *
     * @var list<string>
     */
    public const array LISTING_FLOOR_FIELDS = [
        'description',
        'listing_duration',
        'listing_fee',
        'listing_languages',
        'listing_regions',
    ];

    /** One entry per EU language is the natural ceiling. */
    public const int MAX_LISTING_LANGUAGES = 24;

    /** Generous but bounded — a job targeting 60 countries is already absurd. */
    public const int MAX_LISTING_REGIONS = 60;

    /**
     * Shared per-field rules. Every field is optional in general (D3 gates on
     * the listed state, not on presence).
     *
     * @return array<string, mixed>
     */
    protected function listingFieldRules(): array
    {
        return [
            'listing_duration' => ['sometimes', 'nullable', 'string', 'max:120'],
            'listing_fee' => ['sometimes', 'nullable', 'string', 'max:120'],

            'listing_languages' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_LISTING_LANGUAGES],
            'listing_languages.*' => ['string', 'distinct', Rule::in(Locale::values())],

            'listing_regions' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_LISTING_REGIONS],
            'listing_regions.*' => ['string', 'size:2', 'distinct', 'regex:/^[A-Z]{2}$/'],

            'listing_examples_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
        ];
    }

    /**
     * Uppercase-normalise region codes before validation, so a client posting
     * `["ie"]` is accepted and STORED as `["IE"]` — one canonical casing in the
     * jsonb, which a future region filter can match without `ILIKE`.
     */
    protected function normalizeListingRegions(): void
    {
        $regions = $this->input('listing_regions');

        if (! is_array($regions)) {
            return;
        }

        $this->merge([
            'listing_regions' => array_map(
                static fn (mixed $code): mixed => is_string($code) ? strtoupper(trim($code)) : $code,
                $regions,
            ),
        ]);
    }

    /**
     * The D3 predicate: which floor fields would still be empty AFTER this
     * write lands. Reads the payload where the payload speaks and the stored
     * row everywhere else, so a partial PATCH is judged on its RESULT — the
     * same merged-state discipline the brand floor uses (AH-053, D6).
     *
     * @return list<string>
     */
    protected function missingListingFloorFields(Campaign $campaign): array
    {
        $missing = [];

        foreach (self::LISTING_FLOOR_FIELDS as $field) {
            $value = $this->has($field) ? $this->input($field) : $campaign->{$field};

            if (! self::listingValueFilled($value)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * "Filled" agrees with the creator floor's `isFilled()`: a string counts
     * only when it is non-empty after trimming (so a space bar cannot satisfy
     * the floor), an array only when it has at least one entry.
     */
    protected static function listingValueFilled(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        return is_string($value) && trim($value) !== '';
    }
}
