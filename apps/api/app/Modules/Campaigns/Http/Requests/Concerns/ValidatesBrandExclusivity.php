<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Requests\Concerns;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Carbon;

/**
 * AH-120 — the write-side rules for brand exclusivity, shared by the create and
 * the edit request ("creators selected for this campaign can't apply to
 * campaigns of: [brands]", optionally until a date).
 *
 *   - `exclusive_brand_ids` — brand ULIDs of THIS agency, at most
 *     {@see self::MAX_EXCLUSIVE_BRANDS}, distinct. Never the campaign's own
 *     brand (a no-op for this campaign and ambiguous for future ones). An
 *     ARCHIVED brand cannot be newly added, but one that is already on the
 *     stored list stays valid (an archived brand stays blocked, and a save of
 *     an unrelated field must not fail on it).
 *   - `exclusivity_ends_on` — a UTC calendar date `Y-m-d`; null clears it. A
 *     date in the past is refused ONLY when it differs from the stored value,
 *     so editing an unrelated field of a campaign whose date has since passed
 *     is never refused.
 *
 * The flag-OFF refusal lives in the controller (it answers with a domain code),
 * exactly as AH-118's region switch does.
 */
trait ValidatesBrandExclusivity
{
    /** Generous for any agency's brand list, bounded for the pivot and the form. */
    public const int MAX_EXCLUSIVE_BRANDS = 50;

    /**
     * @return array<string, mixed>
     */
    protected function brandExclusivityRules(): array
    {
        return [
            'exclusive_brand_ids' => ['sometimes', 'array', 'max:'.self::MAX_EXCLUSIVE_BRANDS],
            'exclusive_brand_ids.*' => ['string', 'distinct'],
            'exclusivity_ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Resolved against the data, so it runs after the shape rules. `$campaign`
     * is the stored row on edit, null on create.
     */
    protected function validateBrandExclusivity(Validator $validator, ?Campaign $campaign): void
    {
        if ($validator->errors()->hasAny(['exclusive_brand_ids', 'exclusive_brand_ids.*', 'exclusivity_ends_on'])) {
            return;
        }

        // Another agency's campaign is the controller's 404, not a field error
        // that would read this campaign's brand or list.
        $agency = $this->route('agency');
        if ($campaign instanceof Campaign && $agency instanceof Agency && $campaign->agency_id !== $agency->id) {
            return;
        }

        $this->validateExclusiveBrands($validator, $campaign);
        $this->validateExclusivityDate($validator, $campaign);
    }

    private function validateExclusiveBrands(Validator $validator, ?Campaign $campaign): void
    {
        $ulids = $this->input('exclusive_brand_ids');

        if (! $this->has('exclusive_brand_ids') || ! is_array($ulids) || $ulids === []) {
            return;
        }

        $agency = $this->route('agency');
        $agencyId = $agency instanceof Agency ? $agency->id : null;

        $brands = Brand::query()->withTrashed()
            ->where('agency_id', $agencyId)
            ->whereIn('ulid', array_values($ulids))
            ->get()
            ->keyBy('ulid');

        $ownBrandUlid = $campaign instanceof Campaign
            ? $campaign->brand?->ulid
            : (is_string($this->input('brand_id')) ? $this->input('brand_id') : null);

        $alreadyListed = $campaign instanceof Campaign
            ? $campaign->exclusiveBrands()->pluck('brands.ulid')->all()
            : [];

        foreach (array_values($ulids) as $index => $ulid) {
            $field = "exclusive_brand_ids.{$index}";
            $brand = is_string($ulid) ? $brands->get($ulid) : null;

            if (! $brand instanceof Brand) {
                $validator->errors()->add($field, 'Choose a brand from this agency.');

                continue;
            }

            if ($ulid === $ownBrandUlid) {
                $validator->errors()->add($field, 'A campaign cannot block its own brand.');

                continue;
            }

            if ($brand->trashed() && ! in_array($ulid, $alreadyListed, true)) {
                $validator->errors()->add($field, 'An archived brand cannot be added.');
            }
        }
    }

    private function validateExclusivityDate(Validator $validator, ?Campaign $campaign): void
    {
        $date = $this->input('exclusivity_ends_on');

        if (! $this->has('exclusivity_ends_on') || ! is_string($date) || $date === '') {
            return; // absent, or null: clearing is always allowed
        }

        $stored = $campaign?->exclusivity_ends_on?->toDateString();

        if ($date === $stored) {
            return; // unchanged: never refused for having passed since
        }

        if ($date < Carbon::now('UTC')->toDateString()) {
            $validator->errors()->add('exclusivity_ends_on', 'Choose today or a later date.');
        }
    }
}
