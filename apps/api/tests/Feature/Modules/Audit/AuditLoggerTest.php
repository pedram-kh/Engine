<?php

declare(strict_types=1);

use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Exceptions\MissingAuditReasonException;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('AuditLogger and Audit facade resolve the same singleton', function (): void {
    $direct = app(AuditLogger::class);
    $facadeRoot = Audit::getFacadeRoot();

    expect($facadeRoot)->toBe($direct);
});

it('writes through DI and through the facade produce equivalent rows', function (): void {
    $user = User::factory()->createOne();
    $subject = User::factory()->createOne();

    $sharedArgs = [
        'action' => AuditAction::AuthLoginSucceeded,
        'actor' => $user,
        'subject' => $subject,
        'metadata' => ['ip' => '203.0.113.10'],
    ];

    /** @var AuditLogger $direct */
    $direct = app(AuditLogger::class);
    $rowDirect = $direct->log(...$sharedArgs);

    /** @var AuditLog $rowFacade */
    $rowFacade = Audit::log(...$sharedArgs);

    $stripVolatile = static fn (array $a): array => collect($a)
        ->except(['id', 'ulid', 'created_at'])
        ->all();

    expect($stripVolatile($rowDirect->getAttributes()))
        ->toBe($stripVolatile($rowFacade->getAttributes()));

    expect($rowDirect->id)->not->toBe($rowFacade->id, 'each call must persist its own row');
});

it('enforces reason at the service layer for reason-mandatory actions', function (): void {
    /** @var AuditLogger $logger */
    $logger = app(AuditLogger::class);

    expect(fn () => $logger->log(action: AuditAction::AuthAccountUnlocked))
        ->toThrow(MissingAuditReasonException::class);

    expect(fn () => $logger->log(
        action: AuditAction::AuthAccountUnlocked,
        reason: '   ',
    ))->toThrow(MissingAuditReasonException::class, 'auth.account_unlocked');
});

it('accepts a non-empty reason for reason-mandatory actions and trims it', function (): void {
    /** @var AuditLogger $logger */
    $logger = app(AuditLogger::class);

    $row = $logger->log(
        action: AuditAction::AuthAccountUnlocked,
        reason: '   support ticket #4711   ',
    );

    expect($row->reason)->toBe('support ticket #4711');
});

it('auto-derives actor_type=system and actor_role=system when no actor and no auth user', function (): void {
    /** @var AuditLogger $logger */
    $logger = app(AuditLogger::class);
    $row = $logger->log(action: AuditAction::AuthLoginFailed);

    expect($row->actor_type)->toBe('system')
        ->and($row->actor_role)->toBe('system')
        ->and($row->actor_id)->toBeNull();
});

it('runs cleanly with no HTTP request and no overrides', function (): void {
    // Seeders, artisan commands, and queued jobs may invoke AuditLogger
    // outside an HTTP request lifecycle. We simulate that by binding an
    // empty Request (no REMOTE_ADDR, no User-Agent) — the same shape Laravel
    // hands a queue worker that has not had an HTTP context restored.
    app()->instance('request', new Request);

    /** @var AuditLogger $logger */
    $logger = app(AuditLogger::class);
    $row = $logger->log(action: AuditAction::AuthLoginFailed);

    expect($row->exists)->toBeTrue('the row must persist even with no HTTP context')
        ->and($row->actor_id)->toBeNull()
        ->and($row->actor_type)->toBe('system')
        ->and($row->actor_role)->toBe('system')
        ->and($row->ip)->toBeNull()
        ->and($row->user_agent)->toBeNull();
});

it('auto-derives actor from auth()->user() when no explicit actor passed', function (): void {
    $user = User::factory()->createOne();
    auth()->guard()->setUser($user);

    /** @var AuditLogger $logger */
    $logger = app(AuditLogger::class);
    $row = $logger->log(action: AuditAction::AuthLoginSucceeded);

    expect($row->actor_type)->toBe('user');
    expect($row->actor_id)->toBe($user->id);
});

it('auto-derives agency_id from TenancyContext when not explicitly passed', function (): void {
    $agency = Agency::factory()->create();
    /** @var TenancyContext $ctx */
    $ctx = app(TenancyContext::class);

    $row = $ctx->runAs($agency->id, function () {
        /** @var AuditLogger $logger */
        $logger = app(AuditLogger::class);

        return $logger->log(action: AuditAction::AuthLoginSucceeded);
    });

    expect($row->agency_id)->toBe($agency->id);
});

it('explicit agencyId overrides TenancyContext', function (): void {
    $a1 = Agency::factory()->create();
    $a2 = Agency::factory()->create();

    /** @var TenancyContext $ctx */
    $ctx = app(TenancyContext::class);
    $row = $ctx->runAs($a1->id, function () use ($a2) {
        /** @var AuditLogger $logger */
        $logger = app(AuditLogger::class);

        return $logger->log(
            action: AuditAction::AuthLoginSucceeded,
            agencyId: $a2->id,
        );
    });

    expect($row->agency_id)->toBe($a2->id);
});

it('records subject_type, subject_id, and subject_ulid for a model subject', function (): void {
    $user = User::factory()->createOne();

    /** @var AuditLogger $logger */
    $logger = app(AuditLogger::class);
    $row = $logger->log(
        action: AuditAction::AuthLoginSucceeded,
        subject: $user,
    );

    expect($row->subject_type)->toBe($user->getMorphClass())
        ->and($row->subject_id)->toBe($user->id)
        ->and($row->subject_ulid)->toBe($user->ulid);
});

it('persists explicit ip / user_agent / actor_role values verbatim', function (): void {
    /** @var AuditLogger $logger */
    $logger = app(AuditLogger::class);

    $row = $logger->log(
        action: AuditAction::AuthLoginSucceeded,
        actorType: 'webhook',
        actorRole: 'stripe',
        ip: '198.51.100.7',
        userAgent: 'Stripe/1.0',
    );

    expect($row->actor_type)->toBe('webhook')
        ->and($row->actor_role)->toBe('stripe')
        ->and($row->ip)->toBe('198.51.100.7')
        ->and($row->user_agent)->toBe('Stripe/1.0');
});

it('coerces empty before / after / metadata arrays to NULL columns', function (): void {
    /** @var AuditLogger $logger */
    $logger = app(AuditLogger::class);
    $row = $logger->log(action: AuditAction::AuthLoginSucceeded);

    expect($row->before)->toBeNull()
        ->and($row->after)->toBeNull()
        ->and($row->metadata)->toBeNull();
});

it('writes ip/user_agent from the active Request when present', function (): void {
    /** @var AuditLogger $logger */
    $logger = app(AuditLogger::class);

    $req = Request::create('/', 'GET', server: [
        'REMOTE_ADDR' => '203.0.113.42',
        'HTTP_USER_AGENT' => 'PestRunner/1.0',
    ]);
    app()->instance('request', $req);

    $row = $logger->log(action: AuditAction::AuthLoginSucceeded);

    expect($row->ip)->toBe('203.0.113.42')
        ->and($row->user_agent)->toBe('PestRunner/1.0');
});
