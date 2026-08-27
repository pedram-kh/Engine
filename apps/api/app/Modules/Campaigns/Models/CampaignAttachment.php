<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Models;

use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Campaigns\Database\Factories\CampaignAttachmentFactory;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A campaign's link to one of its agency's Files-library documents (Campaign
 * attachments from the Files library, chunk B, AH-092). Per
 * docs/reviews/campaign-attachments-plan.md §2.1.
 *
 * No `agency_id` — both parents already carry the tenant; see the migration
 * docblock for why the attach-write's structural same-agency check, not a
 * denormalized column, is the real boundary. No `ulid` and no independent
 * lifecycle: this row is binary (exists, plus one boolean), addressed only
 * ever nested under its campaign by the FILE's own ulid — never by an
 * identity of its own (the `talent_pool_creators` shape, not
 * `campaign_applications`'s).
 *
 * `visible_to_creators` is deliberately named broader than the UI's
 * "Visible to applicants" label: it also gates an ASSIGNED creator's read via
 * the assignment-detail door (AH-051's survives-disconnect doctrine), not
 * only a Jobs Board applicant's. A column named after the UI label would be
 * semantically narrower than what it actually controls.
 *
 * No SoftDeletes: detach is a hard delete of the LINKAGE, never the file or
 * its S3 object. `hasAttachments()`'s query on {@see AgencyFile} therefore
 * needs no "non-deleted" clause — every row here is live by construction.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $agency_file_id
 * @property bool $visible_to_creators
 * @property int|null $attached_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Campaign $campaign
 * @property-read AgencyFile $agencyFile
 * @property-read User|null $attachedBy
 */
final class CampaignAttachment extends Model
{
    /** @use HasFactory<CampaignAttachmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'campaign_id',
        'agency_file_id',
        'visible_to_creators',
        'attached_by_user_id',
    ];

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<AgencyFile, $this>
     */
    public function agencyFile(): BelongsTo
    {
        return $this->belongsTo(AgencyFile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function attachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attached_by_user_id');
    }

    /**
     * The single "creator visible" definition (D4): the flag is ON AND the
     * file it points to is still live. `whereHas` with no closure asserts
     * existence through {@see AgencyFile}'s own global SoftDeletes scope, so
     * a soft-deleted file's attachment is excluded without a second clause.
     *
     * Every creator-facing surface MUST compose this scope with its own
     * outer campaign gate (assignment ownership, or JobsBoardVisibility)
     * before ever constructing a presenter from the result — this scope is
     * not itself an authorization boundary, only the shared "what counts as
     * visible" definition both gated surfaces share.
     *
     * @param  Builder<CampaignAttachment>  $query
     * @return Builder<CampaignAttachment>
     */
    public function scopeCreatorVisible(Builder $query): Builder
    {
        return $query
            ->where('visible_to_creators', true)
            ->whereHas('agencyFile');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visible_to_creators' => 'boolean',
        ];
    }

    protected static function newFactory(): CampaignAttachmentFactory
    {
        return CampaignAttachmentFactory::new();
    }
}
