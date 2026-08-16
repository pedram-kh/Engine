<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Boards\Models\Board;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Database\Factories\CampaignFactory;
use App\Modules\Campaigns\Enums\CampaignObjective;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A campaign — a first-class Phase 1 entity owned by an agency and a brand
 * (Sprint 8 Chunk 1, D-1). See docs/03-DATA-MODEL.md §7.
 *
 * Not Audited via the trait: `campaign.created` / `campaign.updated` are
 * logged MANUALLY by CampaignController (mirroring the Brand precedent),
 * because the free-text `brief` blob must never land in an audit snapshot.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $brand_id
 * @property string $name
 * @property string|null $description
 * @property CampaignObjective $objective
 * @property CampaignStatus $status
 * @property int|null $budget_minor_units
 * @property string|null $budget_currency
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $posting_window_starts_at
 * @property Carbon|null $posting_window_ends_at
 * @property array<string, mixed>|null $brief
 * @property int|null $target_creator_count
 * @property int $created_by_user_id
 * @property Carbon|null $published_at
 * @property Carbon|null $completed_at
 * @property bool $is_marketplace_visible
 * @property Carbon|null $marketplace_open_at
 * @property Carbon|null $marketplace_close_at
 * @property bool $requires_per_campaign_contract
 * @property bool $creator_posts_content
 * @property bool $listed_on_jobs_board
 * @property string|null $listing_duration
 * @property string|null $listing_fee
 * @property list<string>|null $listing_languages
 * @property list<string>|null $listing_regions
 * @property string|null $listing_examples_url
 * @property Carbon|null $listed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class Campaign extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * The campaign statuses in which a listing may be VISIBLE (D5). A terminal
     * campaign keeps its `listed_on_jobs_board` value — the flag records the
     * agency's intent and is never auto-cleared by a status change — so the
     * status filter lives here, at read time, next to the flag it qualifies.
     *
     * @var list<string>
     */
    public const array LISTABLE_STATUSES = ['draft', 'active', 'paused'];

    protected $attributes = [
        'status' => 'draft',
        'is_marketplace_visible' => false,
        'requires_per_campaign_contract' => false,
        // AH-069 D1 (Q1, the safety floor) — mirrors the column's DB default.
        // A campaign built without naming the field expects creator posting,
        // i.e. the lifecycle that has always shipped. The create FORM sends
        // `false` explicitly; the default only governs the paths that don't.
        'creator_posts_content' => true,
        'listed_on_jobs_board' => false,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'brand_id',
        'name',
        'description',
        'objective',
        'status',
        'budget_minor_units',
        'budget_currency',
        'starts_at',
        'ends_at',
        'posting_window_starts_at',
        'posting_window_ends_at',
        'brief',
        'target_creator_count',
        'created_by_user_id',
        'published_at',
        'completed_at',
        'is_marketplace_visible',
        'marketplace_open_at',
        'marketplace_close_at',
        'requires_per_campaign_contract',
        'creator_posts_content',
        'listed_on_jobs_board',
        'listing_duration',
        'listing_fee',
        'listing_languages',
        'listing_regions',
        'listing_examples_url',
        // AH-056 D4 — DISPLAY metadata, written only on the false→true listing
        // flip in CampaignController::update(). Fillable for factory/test
        // ergonomics only: no FormRequest validates it, so `fill(validated())`
        // can never reach it from an HTTP request.
        'listed_at',
    ];

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * Includes trashed rows: archiving a brand is a soft delete, and a
     * campaign must keep rendering its (historical) brand after the archive —
     * otherwise the SoftDeletes scope nulls the relation and every campaign
     * serialization crashes (the production July-Wave-4 incident).
     *
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<CampaignAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(CampaignAssignment::class);
    }

    /**
     * Creator applications to this campaign's jobs-board listing (AH-056, D1).
     *
     * Distinct from {@see assignments()} on purpose — an application is a
     * creator-initiated expression of interest, an assignment is an
     * agency-issued offer, and the two must not share a row (see the
     * `campaign_applications` migration for the collision that would cause).
     * The board card's applicant count is a `withCount('applications')` over
     * this relation, unfiltered by status: pending, accepted and rejected all
     * count, because the number a creator reads is "how much interest does this
     * job have", not "how many are still in the running" (D4).
     *
     * ⚠ Emphatically NOT the same number as `CampaignResource.assignment_count`,
     * which stays an unfiltered count of `assignments` and keeps its shipped
     * meaning. Nothing about this relation changes that field.
     *
     * @return HasMany<CampaignApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(CampaignApplication::class);
    }

    /**
     * The per-creator once-only job-posted notification stamps (AH-056, D7).
     * Read by the fan-out service to skip already-notified creators; never
     * emitted in any resource.
     *
     * @return HasMany<CampaignJobNotification, $this>
     */
    public function jobNotifications(): HasMany
    {
        return $this->hasMany(CampaignJobNotification::class);
    }

    /**
     * The campaign's Kanban board (Sprint 12 Chunk 1, D-1). 1:1, lazily
     * provisioned on first GET (D-4) — null until then.
     *
     * @return HasOne<Board, $this>
     */
    public function board(): HasOne
    {
        return $this->hasOne(Board::class);
    }

    /**
     * The jobs-board visibility predicate (AH-054, D1/D5) — the SINGLE source
     * of truth for "is this campaign showing on the jobs board right now?".
     *
     * Two legs, because the flag alone is not the answer: the agency's stored
     * intent (`listed_on_jobs_board`) AND a non-terminal status. D5 rules that
     * a status change never rewrites the flag — an auto-clear would be a hidden
     * write to user intent, and a campaign that is reopened should light up
     * again — so a `completed` / `cancelled` campaign can legitimately sit at
     * `listed_on_jobs_board = true` while being invisible. That inertness is
     * enforced HERE, at read time, exactly like the `ends_at` auto-delist the
     * arc adds in chunk 3 (one mechanism for both, so the two cannot drift).
     *
     * NO consumer in this chunk — the feature ships dark (D10). The scope
     * exists now so chunk 3's creator-facing surface binds to a tested
     * contract instead of re-deriving the predicate and forgetting a leg.
     *
     * @param  Builder<Campaign>  $query
     * @return Builder<Campaign>
     */
    public function scopeListedOnJobsBoard(Builder $query): Builder
    {
        return $query
            ->where('listed_on_jobs_board', true)
            ->whereIn('status', self::LISTABLE_STATUSES);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'objective' => CampaignObjective::class,
            'status' => CampaignStatus::class,
            'budget_minor_units' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'posting_window_starts_at' => 'datetime',
            'posting_window_ends_at' => 'datetime',
            'brief' => 'array',
            'target_creator_count' => 'integer',
            'published_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_marketplace_visible' => 'boolean',
            'marketplace_open_at' => 'datetime',
            'marketplace_close_at' => 'datetime',
            'requires_per_campaign_contract' => 'boolean',
            'creator_posts_content' => 'boolean',
            'listed_on_jobs_board' => 'boolean',
            'listing_languages' => 'array',
            'listing_regions' => 'array',
            'listed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CampaignFactory
    {
        return CampaignFactory::new();
    }
}
