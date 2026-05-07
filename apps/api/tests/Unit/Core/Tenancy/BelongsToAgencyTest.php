<?php

declare(strict_types=1);

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Core\Tenancy\MissingAgencyContextException;
use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Models\Agency;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Tenancy\TenantScopedFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Schema::create('tenant_scoped_fixtures', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
        $table->string('name');
        $table->timestamps();
    });
});

afterEach(function (): void {
    app(TenancyContext::class)->forget();
    Schema::dropIfExists('tenant_scoped_fixtures');
});

it('global scope filters rows by the current agency context', function (): void {
    $agencyA = Agency::factory()->create();
    $agencyB = Agency::factory()->create();

    TenantScopedFixture::query()->create(['agency_id' => $agencyA->id, 'name' => 'A1']);
    TenantScopedFixture::query()->create(['agency_id' => $agencyA->id, 'name' => 'A2']);
    TenantScopedFixture::query()->create(['agency_id' => $agencyB->id, 'name' => 'B1']);

    app(TenancyContext::class)->setAgencyId($agencyA->id);

    expect(TenantScopedFixture::query()->count())->toBe(2)
        ->and(TenantScopedFixture::query()->pluck('name')->all())
        ->toEqualCanonicalizing(['A1', 'A2']);
});

it('global scope is a no-op when no agency context is set', function (): void {
    $agencyA = Agency::factory()->create();
    $agencyB = Agency::factory()->create();

    TenantScopedFixture::query()->create(['agency_id' => $agencyA->id, 'name' => 'A1']);
    TenantScopedFixture::query()->create(['agency_id' => $agencyB->id, 'name' => 'B1']);

    expect(app(TenancyContext::class)->hasAgency())->toBeFalse()
        ->and(TenantScopedFixture::query()->count())->toBe(2);
});

it('cross-agency find returns null', function (): void {
    $agencyA = Agency::factory()->create();
    $agencyB = Agency::factory()->create();

    $rowInB = TenantScopedFixture::query()->create([
        'agency_id' => $agencyB->id,
        'name' => 'B1',
    ]);

    app(TenancyContext::class)->setAgencyId($agencyA->id);

    expect(TenantScopedFixture::query()->find($rowInB->id))->toBeNull();
});

it('throws when creating a tenant-scoped row without agency_id and no context', function (): void {
    expect(fn () => TenantScopedFixture::query()->create(['name' => 'orphan']))
        ->toThrow(MissingAgencyContextException::class);
});

it('auto-fills agency_id from the active context on create', function (): void {
    $agency = Agency::factory()->create();

    app(TenancyContext::class)->setAgencyId($agency->id);

    $row = TenantScopedFixture::query()->create(['name' => 'autofill']);

    expect($row->agency_id)->toBe($agency->id);
});

it('TenancyContext::runAs restores the previous context on exit', function (): void {
    $context = app(TenancyContext::class);
    $context->setAgencyId(1);

    $context->runAs(2, function () use ($context): void {
        expect($context->agencyId())->toBe(2);
    });

    expect($context->agencyId())->toBe(1);
});

it('withoutGlobalScope explicitly bypasses tenancy filtering', function (): void {
    $agencyA = Agency::factory()->create();
    $agencyB = Agency::factory()->create();

    TenantScopedFixture::query()->create(['agency_id' => $agencyA->id, 'name' => 'A1']);
    TenantScopedFixture::query()->create(['agency_id' => $agencyB->id, 'name' => 'B1']);

    app(TenancyContext::class)->setAgencyId($agencyA->id);

    expect(
        TenantScopedFixture::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->count(),
    )->toBe(2);
});
