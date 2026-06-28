<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-005 — creator contact columns migration test
|--------------------------------------------------------------------------
|
| The four optional contact columns exist after migrate:fresh and are
| nullable (a creator persists with all four left unset).
|
*/

it('creators table has the four AH-005 contact columns', function (): void {
    foreach (['phone', 'whatsapp', 'address_street', 'address_postal_code'] as $column) {
        expect(Schema::hasColumn('creators', $column))
            ->toBeTrue("creators.{$column} should exist");
    }
});

it('persists a creator with all four contact columns null (nullable)', function (): void {
    $creator = CreatorFactory::new()->createOne();

    expect($creator->phone)->toBeNull()
        ->and($creator->whatsapp)->toBeNull()
        ->and($creator->address_street)->toBeNull()
        ->and($creator->address_postal_code)->toBeNull();
});

it('persists a creator with populated contact details', function (): void {
    $creator = CreatorFactory::new()->withContact()->createOne();
    $creator->refresh();

    expect($creator->phone)->toBe('+1 555 0100')
        ->and($creator->whatsapp)->toBe('+1 555 0142')
        ->and($creator->address_street)->toBe('12 Market Street')
        ->and($creator->address_postal_code)->toBe('D02 XY45');
});
