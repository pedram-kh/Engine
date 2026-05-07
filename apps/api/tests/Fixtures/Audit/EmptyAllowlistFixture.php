<?php

declare(strict_types=1);

namespace Tests\Fixtures\Audit;

use App\Modules\Audit\Concerns\Audited;
use App\Modules\Audit\Contracts\Auditable;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Test fixture: a minimal Eloquent model that uses the {@see Audited}
 * trait and implements {@see Auditable} with an explicitly empty
 * allowlist.
 *
 * Demonstrates that the allowlist contract is honoured for any consumer
 * of the trait, not just {@see User}: when
 * the consumer returns an empty list, audit snapshots are empty and
 * cannot leak attributes.
 */
final class EmptyAllowlistFixture extends Model implements Auditable
{
    use Audited;

    public $timestamps = false;

    protected $table = 'audit_empty_allowlist_fixtures';

    public function auditableAllowlist(): array
    {
        return [];
    }
}
