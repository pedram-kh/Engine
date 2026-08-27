<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Http\Resources\BrandOptionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The thin `{id, name}` projection for admin agency `<select>` pickers (AH-090,
 * D6 — the {@see BrandOptionResource} shape
 * applied to the admin side).
 *
 * The automation-rule dialog's agency picker needs an agency's ULID and name,
 * nothing else: not the member count the admin agencies table computes with an
 * extra aggregate query, not the suspension columns, not the subscription tier.
 *
 * It exists for the reason AH-085 exists: the paginated admin agencies index
 * defaults to 25 per page, so a picker reading it would silently lose the
 * alphabetical tail the moment the platform has a 26th agency — an unreachable
 * option in a form, with no error anywhere. That bug class has now bitten three
 * times (AH-066, AH-085, and this dialog), which is why the decision procedure
 * is written down in docs/02-CONVENTIONS.md §3.5 rather than re-derived a fourth
 * time: a bounded, operator-created set gets an unpaginated `?for=select`
 * projection; an unbounded, user-generated one gets a server-side `?q=` search. A
 * paginated index is never consumed as a select.
 */
final class AgencyOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $agency = $this->resource;
        assert($agency instanceof Agency);

        return [
            'id' => $agency->ulid,
            'type' => 'agencies',
            'attributes' => [
                'name' => $agency->name,
            ],
        ];
    }
}
