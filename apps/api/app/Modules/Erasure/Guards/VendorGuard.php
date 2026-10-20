<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Guards;

use App\Modules\Erasure\Selection\Selections;
use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Stage 4g: fail closed on any real vendor.
 *
 * Production runs every provider as `mock` (read-back A, 2026-10-20), so no
 * vendor-side delete is built. If a row of the subject was ever created through
 * a real provider, the vendor still holds the person (a connected account, an
 * identity check, a signed envelope) and the local ids that would let an
 * operator find it are about to be erased. So the run is refused until the
 * vendor step for that provider exists: an explicit edit here, to the pinned
 * provider contracts and to a spec, not a quiet default.
 *
 * The messages name a table and a provider, never a person.
 */
final class VendorGuard
{
    /** Providers whose rows live entirely in this database. */
    private const array LOCAL_PROVIDERS = ['mock'];

    /** E-sign values that are not a vendor: the in-app click-through contract. */
    private const array LOCAL_ESIGN = ['mock', 'internal'];

    /** @return list<string> reasons the run must be refused; empty when every provider is local */
    public function refusals(SubjectSet $subject, ?ConnectionInterface $db = null): array
    {
        $db ??= DB::connection();
        $reasons = [];

        $this->add($reasons, 'creator_kyc_verifications', $db->table('creator_kyc_verifications')->where('creator_id', $subject->creatorId)->whereNotIn('provider', self::LOCAL_PROVIDERS)->groupBy('provider')->selectRaw('provider, count(*) as n')->get());
        $this->add($reasons, 'creator_payout_methods', $db->table('creator_payout_methods')->where('creator_id', $subject->creatorId)->whereNotIn('provider', self::LOCAL_PROVIDERS)->groupBy('provider')->selectRaw('provider, count(*) as n')->get());
        $this->add($reasons, 'contracts (signed by the creator)', $db->table('contracts')->where('signed_by_creator_id', $subject->creatorId)->whereNotNull('signature_provider')->whereNotIn('signature_provider', self::LOCAL_ESIGN)->groupBy('signature_provider')->selectRaw('signature_provider as provider, count(*) as n')->get());

        foreach (Selections::all() as $selection) {
            if ($selection->id === 'integration_events.payload') {
                $this->add($reasons, 'integration_events', $selection->builder($subject, $db)->whereNotIn('provider', self::LOCAL_PROVIDERS)->groupBy('provider')->selectRaw('provider, count(*) as n')->get());
            }
        }

        // Social accounts carry no provider column; the configured driver is the only signal.
        $socialDriver = config('integrations.social.driver');
        if ($subject->socialAccountIds !== [] && $socialDriver !== 'mock') {
            $reasons[] = 'creator_social_accounts: the social provider driver is not mock, so the tokens are live at a third party and revoking them is not built';
        }

        return $reasons;
    }

    /**
     * @param  list<string>  $reasons
     * @param  iterable<stdClass>  $rows
     */
    private function add(array &$reasons, string $table, iterable $rows): void
    {
        foreach ($rows as $row) {
            $reasons[] = sprintf("%s: %d row(s) from the non-mock provider '%s'; the vendor-side delete is not built", $table, (int) $row->n, (string) $row->provider);
        }
    }
}
