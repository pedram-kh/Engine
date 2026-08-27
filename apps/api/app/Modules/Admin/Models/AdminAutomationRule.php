<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Admin\Database\Factories\AdminAutomationRuleFactory;
use App\Modules\Admin\Enums\AutomationAction;
use App\Modules\Admin\Enums\AutomationEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A platform-level automation rule (AH-090, D1).
 *
 * Tenant-less BY DESIGN — a platform capability, like a feature flag, not an
 * agency-scoped resource. No `agency_id` column and no BelongsToAgency: the
 * agency a rule TARGETS lives in `config`, which belongs to the action.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property AutomationEvent $event
 * @property AutomationAction $action
 * @property array<string, mixed>|null $config
 * @property bool $enabled
 * @property Carbon|null $last_fired_at
 * @property int $fire_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class AdminAutomationRule extends Model
{
    /** @use HasFactory<AdminAutomationRuleFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * The fire trace's zero state, in memory as well as in the schema — a rule
     * that has just been created reads `fire_count = 0`, not null, so the
     * response the create endpoint returns matches the one the list returns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'fire_count' => 0,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'event',
        'action',
        'config',
        'enabled',
    ];

    /**
     * The dispatch-time query: enabled rules watching one event.
     *
     * Deliberately NOT "rules that will fire" — whether a rule fires is decided
     * in the job, against live state, because minutes can pass between this read
     * and the worker picking the job up (AH-090, D5).
     *
     * @param  Builder<AdminAutomationRule>  $query
     * @return Builder<AdminAutomationRule>
     */
    public function scopeEnabledFor(Builder $query, AutomationEvent $event): Builder
    {
        return $query->where('event', $event->value)->where('enabled', true);
    }

    /**
     * The target agency's ULID for a `connect_to_agency` rule, or null when the
     * config is missing or malformed (a hand-edited row, or an action whose
     * config shape does not include one).
     */
    public function targetAgencyUlid(): ?string
    {
        $value = $this->config['agency_id'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The audit reason a fire writes. Structural, not decorative: the
     * `admin_connected` verb refuses to be written without a reason, and this is
     * always ≥ 10 characters by construction (the prefix alone is longer).
     */
    public function firedReason(): string
    {
        return "Auto-connected by rule #{$this->id} ({$this->name}).";
    }

    /**
     * Record a successful fire — the cheap trace (D5). Not the history: the
     * audit row per fire is that. Skips do NOT bump either column, so
     * `fire_count` answers "how many times did this rule actually do something".
     */
    public function recordFire(): void
    {
        $this->forceFill([
            'last_fired_at' => now(),
            'fire_count' => $this->fire_count + 1,
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AutomationEvent::class,
            'action' => AutomationAction::class,
            'config' => 'array',
            'enabled' => 'boolean',
            'last_fired_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AdminAutomationRuleFactory
    {
        return AdminAutomationRuleFactory::new();
    }
}
