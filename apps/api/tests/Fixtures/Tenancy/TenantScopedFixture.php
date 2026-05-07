<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tenancy;

use App\Core\Tenancy\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model used only by tenancy tests. Mirrors the shape of every
 * future tenant-scoped model (Brand, Campaign, etc.) so we can prove the
 * BelongsToAgency trait works in isolation, without coupling the tests
 * to a real domain model that may evolve.
 */
final class TenantScopedFixture extends Model
{
    use BelongsToAgency;

    protected $table = 'tenant_scoped_fixtures';

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'name'];
}
