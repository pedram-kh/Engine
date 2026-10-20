<?php

declare(strict_types=1);

use App\Modules\Erasure\Registry\AuditPayloadKeys;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/*
 * AH-104a Stage 2 — the audit-metadata guard (plan §4.7).
 *
 * The Stage 0 parser (tools/enumerate-audit-actions.php --json) reads every
 * audit call site and Audited allowlist under app/. Any literal payload key, or
 * any payload it cannot read, that AuditPayloadKeys has not decided fails here.
 */

/** @return array{keys: array<string, list<string>>, dynamic: list<string>} */
function auditScan(): array
{
    $process = new Process([PHP_BINARY, 'tools/enumerate-audit-actions.php', '--json'], base_path());
    $process->setTimeout(120);
    $process->mustRun();

    $scan = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

    return $scan;
}

it('has decided every literal audit payload key and every unreadable payload under app/', function (): void {
    $violations = AuditPayloadKeys::violations(auditScan());

    expect($violations)->toBe([], "Undecided audit payloads:\n - ".implode("\n - ", $violations));
});

it('fails on a key nobody decided', function (): void {
    $scan = ['keys' => ['contact_phone' => ['metadata']], 'dynamic' => []];

    expect(AuditPayloadKeys::violations($scan))
        ->toContain('Audit payload key `contact_phone` (metadata) is not decided: add it to scrubKeys(), keptKeys() or notPersonalKeys() in app/Modules/Erasure/Registry/AuditPayloadKeys.php.');
});

it('fails on a payload the parser cannot read in a file that is not listed', function (): void {
    $scan = ['keys' => [], 'dynamic' => ['apps/api/app/Modules/Creators/Http/NewController.php|metadata']];

    expect(implode("\n", AuditPayloadKeys::violations($scan)))
        ->toContain('Audit payload `apps/api/app/Modules/Creators/Http/NewController.php|metadata` is not statically readable and not listed');
});

it('does not exempt a new payload param in an already-listed file', function (): void {
    $scan = ['keys' => [], 'dynamic' => ['apps/api/app/Modules/Brands/Http/Controllers/BrandController.php|metadata']];

    expect(AuditPayloadKeys::violations($scan))->not->toBe([]);
});

it('fails when a key is both scrubbed and kept', function (): void {
    expect(array_intersect_key(AuditPayloadKeys::scrubKeys(), AuditPayloadKeys::keptKeys()))->toBe([]);
});

it('carries the three Stage 0 amendments as accepted at Checkpoint 0', function (): void {
    expect(AuditPayloadKeys::scrubKeys())->toHaveKeys(['before', 'after', 'welcome_message'])
        ->and(AuditPayloadKeys::keptKeys())->toHaveKey('note')
        ->and(AuditPayloadKeys::scrubKeys())->not->toHaveKey('note');
});
