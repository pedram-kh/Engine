<?php

declare(strict_types=1);

use App\Modules\Creators\Enums\ContractKind;
use App\Modules\Creators\Enums\ContractStatus;
use App\Modules\Creators\Models\Contract;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\ContractTermsRenderer;
use Illuminate\Database\Migrations\Migration;

/**
 * @migration-risk low
 *
 * Backfills the `contracts` table for creators who accepted the
 * click-through agreement BEFORE Sprint 4 Chunk 4 routed acceptance
 * through `contracts` (D-c4-4). Preserves the invariant "every accepted
 * creator has a contracts row" so the continuity query is complete.
 *
 * No-op on current seed/dev data: only Sprint1IdentitySeeder runs, which
 * seeds no creator with `click_through_accepted_at`. The logic below is
 * real (and idempotent) so it does the right thing wherever a real
 * acceptance predates this chunk; on a fresh DB it simply finds nothing.
 *
 * Backfilled rows are marked: `version = 1` (the only version that has ever
 * existed), `signed_at` = the existing timestamp, and
 * `signed_signature_data = { method, version: '1.0', backfilled: true }` —
 * no IP/UA is fabricated, since none was captured retroactively.
 *
 * Idempotent: guarded on `signed_master_contract_id IS NULL`, so a re-run
 * skips creators already linked to a contracts row. Models are used (not
 * raw SQL) so `signed_signature_data` is encrypted at rest via the cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        $source = app(ContractTermsRenderer::class)->source();

        Creator::query()
            ->whereNotNull('click_through_accepted_at')
            ->whereNull('signed_master_contract_id')
            ->withTrashed()
            ->chunkById(200, function ($creators) use ($source): void {
                foreach ($creators as $creator) {
                    $contract = Contract::create([
                        'kind' => ContractKind::MasterUniversal,
                        'subject_type' => Contract::SUBJECT_CREATOR,
                        'subject_id' => $creator->id,
                        'version' => ContractTermsRenderer::versionToInteger('1.0'),
                        'title' => $source['title'],
                        'body_markdown' => $source['markdown'],
                        'signature_provider' => Contract::PROVIDER_INTERNAL,
                        'status' => ContractStatus::Signed,
                        'signed_at' => $creator->click_through_accepted_at,
                        'signed_by_creator_id' => $creator->id,
                        'signed_signature_data' => [
                            'method' => Contract::METHOD_CLICK_THROUGH,
                            'version' => '1.0',
                            'backfilled' => true,
                        ],
                        'created_by_user_id' => $creator->user_id,
                    ]);

                    $creator->forceFill([
                        'signed_master_contract_id' => $contract->id,
                    ])->save();
                }
            });
    }

    public function down(): void
    {
        // Drop only the backfilled rows + unlink the creators that point at
        // them. Identified by the `backfilled: true` marker in the
        // (encrypted) signature data — decrypted via the model cast.
        Contract::query()
            ->where('signature_provider', Contract::PROVIDER_INTERNAL)
            ->get()
            ->each(function (Contract $contract): void {
                $data = $contract->signed_signature_data;
                if (! is_array($data) || ($data['backfilled'] ?? false) !== true) {
                    return;
                }

                Creator::query()
                    ->where('signed_master_contract_id', $contract->id)
                    ->withTrashed()
                    ->update(['signed_master_contract_id' => null]);

                $contract->forceDelete();
            });
    }
};
