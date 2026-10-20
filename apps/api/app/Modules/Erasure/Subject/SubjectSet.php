<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Subject;

use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Everything an erasure run needs to know about its subject, resolved BEFORE
 * any write (plan §4.3): the two row ids, the old email (needed to find rows
 * keyed by the address) and the ids of every row the creator owns or appears
 * on. After the first write those links are gone (the email is replaced, the
 * satellites deleted), so nothing may be resolved lazily later.
 *
 * Resolution reads with the raw query builder, so no tenant scope narrows it
 * and soft-deleted rows are included: a trashed portfolio item is still the
 * creator's. It holds no more than the PII map's section 0 lists.
 *
 * The old email is personal data held in memory only: it is hidden from
 * var_dump/dd, never serialised, and never printed by the reporter.
 */
final readonly class SubjectSet
{
    /**
     * @param  list<int>  $assignmentIds
     * @param  list<int>  $applicationIds
     * @param  list<int>  $messageThreadIds  Threads of the creator's assignments.
     * @param  list<int>  $relationshipThreadIds
     * @param  list<int>  $relationIds  agency_creator_relations
     * @param  list<int>  $blacklistIds  brand_creator_blacklists
     * @param  list<int>  $taxProfileIds
     * @param  list<int>  $payoutMethodIds
     * @param  list<int>  $kycIds
     * @param  list<int>  $socialAccountIds
     * @param  list<int>  $portfolioItemIds
     * @param  list<string>  $vendorIds  provider_account_id, KYC session and decision ids, e-sign envelope ids
     */
    public function __construct(
        public int $userId,
        public string $userUlid,
        public int $creatorId,
        public string $creatorUlid,
        public string $oldEmail,
        public array $assignmentIds,
        public array $applicationIds,
        public array $messageThreadIds,
        public array $relationshipThreadIds,
        public array $relationIds,
        public array $blacklistIds,
        public array $taxProfileIds,
        public array $payoutMethodIds,
        public array $kycIds,
        public array $socialAccountIds,
        public array $portfolioItemIds,
        public array $vendorIds,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['userUlid' => $this->userUlid, 'creatorUlid' => $this->creatorUlid, 'oldEmail' => '[hidden]'] + $this->summary();
    }

    /**
     * @throws SubjectNotErasable
     */
    public static function resolve(User $user): self
    {
        if ($user->type !== UserType::Creator) {
            throw SubjectNotErasable::notACreator();
        }
        if ($user->isErased()) {
            throw SubjectNotErasable::alreadyErased();
        }

        // A read through the model is safe (the Audited trait fires on writes only);
        // withTrashed because a soft-deleted creator row is still the subject's.
        $creator = Creator::withTrashed()->where('user_id', $user->id)->first();
        if ($creator === null) {
            throw SubjectNotErasable::noCreatorRow();
        }
        if ($creator->erased_at !== null) {
            throw SubjectNotErasable::alreadyErased();
        }

        $creatorId = $creator->id;
        $assignmentIds = self::ids(DB::table('campaign_assignments')->where('creator_id', $creatorId));
        $taxProfileIds = self::ids(DB::table('creator_tax_profiles')->where('creator_id', $creatorId));
        $payoutMethods = DB::table('creator_payout_methods')->where('creator_id', $creatorId)->get(['id', 'provider_account_id']);
        $kycs = DB::table('creator_kyc_verifications')->where('creator_id', $creatorId)->get(['id', 'provider_session_id', 'provider_decision_id']);
        $envelopes = DB::table('contracts')->where('signed_by_creator_id', $creatorId)->whereNotNull('signature_envelope_id')->pluck('signature_envelope_id');

        $vendorIds = [];
        foreach ($payoutMethods as $row) {
            $vendorIds[] = $row->provider_account_id;
        }
        foreach ($kycs as $row) {
            $vendorIds[] = $row->provider_session_id;
            $vendorIds[] = $row->provider_decision_id;
        }
        foreach ($envelopes as $envelope) {
            $vendorIds[] = $envelope;
        }

        return new self(
            userId: $user->id,
            userUlid: $user->ulid,
            creatorId: $creatorId,
            creatorUlid: $creator->ulid,
            oldEmail: $user->email,
            assignmentIds: $assignmentIds,
            applicationIds: self::ids(DB::table('campaign_applications')->where('creator_id', $creatorId)),
            messageThreadIds: $assignmentIds === [] ? [] : self::ids(DB::table('message_threads')->whereIn('assignment_id', $assignmentIds)),
            relationshipThreadIds: self::ids(DB::table('relationship_threads')->where('creator_id', $creatorId)),
            relationIds: self::ids(DB::table('agency_creator_relations')->where('creator_id', $creatorId)),
            blacklistIds: self::ids(DB::table('brand_creator_blacklists')->where('creator_id', $creatorId)),
            taxProfileIds: $taxProfileIds,
            payoutMethodIds: self::ids(DB::table('creator_payout_methods')->where('creator_id', $creatorId)),
            kycIds: self::ids(DB::table('creator_kyc_verifications')->where('creator_id', $creatorId)),
            socialAccountIds: self::ids(DB::table('creator_social_accounts')->where('creator_id', $creatorId)),
            portfolioItemIds: self::ids(DB::table('creator_portfolio_items')->where('creator_id', $creatorId)),
            vendorIds: self::vendorIdsFrom($vendorIds),
        );
    }

    /**
     * Counts only, never the email or any id: safe to print.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        return [
            'assignments' => count($this->assignmentIds),
            'applications' => count($this->applicationIds),
            'message_threads' => count($this->messageThreadIds),
            'relationship_threads' => count($this->relationshipThreadIds),
            'relations' => count($this->relationIds),
            'blacklists' => count($this->blacklistIds),
            'tax_profiles' => count($this->taxProfileIds),
            'payout_methods' => count($this->payoutMethodIds),
            'kyc_verifications' => count($this->kycIds),
            'social_accounts' => count($this->socialAccountIds),
            'portfolio_items' => count($this->portfolioItemIds),
            'vendor_ids' => count($this->vendorIds),
        ];
    }

    /** @return list<int> */
    private static function ids(Builder $query): array
    {
        $out = [];
        foreach ($query->orderBy('id')->pluck('id') as $id) {
            $out[] = self::intOf($id);
        }

        return $out;
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Vendor ids short enough to match unrelated text are dropped (a one- or
     * two-character id would select half of integration_events).
     *
     * @param  list<mixed>  $raw
     * @return list<string>
     */
    private static function vendorIdsFrom(array $raw): array
    {
        $out = [];
        foreach ($raw as $value) {
            if (is_string($value) && strlen($value) >= 8) {
                $out[$value] = $value;
            }
        }
        ksort($out);

        return array_values($out);
    }
}
