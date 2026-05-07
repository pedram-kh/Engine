<?php

declare(strict_types=1);

use App\Modules\Audit\Facades\Audit;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Foundation\AliasLoader;
use Tests\TestCase;

uses(TestCase::class);

it('binds AuditLogger as a singleton', function (): void {
    expect(app(AuditLogger::class))->toBe(app(AuditLogger::class));
});

it('registers the Audit facade alias', function (): void {
    $aliases = AliasLoader::getInstance()->getAliases();

    expect($aliases)->toHaveKey('Audit');
    expect($aliases['Audit'])->toBe(Audit::class);
});
