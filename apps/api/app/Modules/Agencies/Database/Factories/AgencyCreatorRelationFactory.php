<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Database\Factories;

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Identity\Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgencyCreatorRelation>
 */
final class AgencyCreatorRelationFactory extends Factory
{
    protected $model = AgencyCreatorRelation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => AgencyFactory::new(),
            'creator_id' => CreatorFactory::new(),
            'relationship_status' => RelationshipStatus::Roster,
            'is_blacklisted' => false,
            'total_campaigns_completed' => 0,
            'total_paid_minor_units' => 0,
        ];
    }

    /**
     * Pending magic-link invitation state. The unhashed token is supplied
     * by the test caller; this factory takes the SHA-256 hash so the test
     * can present the unhashed token in the preview/accept call.
     */
    public function prospect(string $unhashedToken = 'test-token-1234567890'): static
    {
        return $this->state(fn (array $attributes): array => [
            'relationship_status' => RelationshipStatus::Prospect,
            'invitation_token_hash' => hash('sha256', $unhashedToken),
            'invitation_expires_at' => now()->addDays(7),
            'invitation_sent_at' => now(),
            'invited_by_user_id' => UserFactory::new()->agencyAdmin(),
        ]);
    }

    public function blacklisted(string $reason = 'Test blacklist'): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_blacklisted' => true,
            'blacklist_scope' => 'agency',
            'blacklist_type' => 'hard',
            'blacklist_reason' => $reason,
            'blacklisted_at' => now(),
        ]);
    }
}
