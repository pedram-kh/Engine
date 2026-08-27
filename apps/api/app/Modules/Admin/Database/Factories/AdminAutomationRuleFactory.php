<?php

declare(strict_types=1);

namespace App\Modules\Admin\Database\Factories;

use App\Modules\Admin\Enums\AutomationAction;
use App\Modules\Admin\Enums\AutomationEvent;
use App\Modules\Admin\Models\AdminAutomationRule;
use App\Modules\Agencies\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdminAutomationRule>
 */
final class AdminAutomationRuleFactory extends Factory
{
    protected $model = AdminAutomationRule::class;

    /**
     * The v1 pair, enabled, with an EMPTY config — a rule whose agency is not
     * named yet, which is a state the job must survive rather than a state the
     * UI can produce. Use {@see self::connectTo()} for a usable rule.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'name' => 'Approved → agency',
            'event' => AutomationEvent::CreatorApproved,
            'action' => AutomationAction::ConnectToAgency,
            'config' => null,
            'enabled' => true,
            'last_fired_at' => null,
            'fire_count' => 0,
        ];
    }

    public function connectTo(Agency $agency): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => "Approved → {$agency->name}",
            'event' => AutomationEvent::CreatorApproved,
            'action' => AutomationAction::ConnectToAgency,
            'config' => ['agency_id' => $agency->ulid],
        ]);
    }

    /** A rule targeting an agency that no longer exists — broken-but-present. */
    public function connectToMissingAgency(): static
    {
        return $this->state(fn (array $attributes): array => [
            'config' => ['agency_id' => (string) Str::ulid()],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => false,
        ]);
    }

    public function fired(int $times = 3): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_fired_at' => now()->subHour(),
            'fire_count' => $times,
        ]);
    }
}
