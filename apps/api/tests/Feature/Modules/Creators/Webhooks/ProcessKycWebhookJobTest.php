<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Models\IntegrationEvent;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\Mock\MockKycProvider;
use App\Modules\Creators\Jobs\ProcessKycWebhookJob;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    app()->bind(KycProvider::class, MockKycProvider::class);
});

function makeKycEventForCreator(Creator $creator, string $result = 'verified', string $eventId = 'evt_proc'): IntegrationEvent
{
    $payload = [
        'event_id' => $eventId,
        'event_type' => 'verification.completed',
        'creator_ulid' => $creator->ulid,
        'verification_result' => $result,
    ];

    return IntegrationEvent::query()->create([
        'provider' => 'kyc',
        'provider_event_id' => $eventId,
        'event_type' => 'verification.completed',
        'payload' => $payload,
        'received_at' => now(),
    ]);
}

function makeProcessKycCreator(): Creator
{
    $user = User::factory()->createOne();

    return CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);
}

it('flips kyc_status to verified + emits CreatorWizardKycCompleted on first transition', function (): void {
    $creator = makeProcessKycCreator();
    $event = makeKycEventForCreator($creator, 'verified');

    (new ProcessKycWebhookJob($event->id))->handle(app(KycProvider::class), app(AuditLogger::class));

    $creator->refresh();
    expect($creator->kyc_status)->toBe(KycStatus::Verified);
    expect($creator->kyc_verified_at)->not->toBeNull();

    $completionAudit = AuditLog::query()->where('action', AuditAction::CreatorWizardKycCompleted)->count();
    expect($completionAudit)->toBe(1);

    $event->refresh();
    expect($event->processed_at)->not->toBeNull();
});

it('does NOT re-emit completion audit on re-run for an already-verified creator (#6 idempotency)', function (): void {
    $creator = makeProcessKycCreator();
    $event = makeKycEventForCreator($creator, 'verified', 'evt_first');

    $logger = app(AuditLogger::class);
    (new ProcessKycWebhookJob($event->id))->handle(app(KycProvider::class), $logger);
    (new ProcessKycWebhookJob($event->id))->handle(app(KycProvider::class), $logger);

    $completionCount = AuditLog::query()->where('action', AuditAction::CreatorWizardKycCompleted)->count();
    expect($completionCount)->toBe(1, 'Re-running the same job MUST NOT re-emit the completion audit row.');
});

it('flips kyc_status to rejected + does NOT emit completion audit', function (): void {
    $creator = makeProcessKycCreator();
    $event = makeKycEventForCreator($creator, 'rejected', 'evt_reject');

    (new ProcessKycWebhookJob($event->id))->handle(app(KycProvider::class), app(AuditLogger::class));

    $creator->refresh();
    expect($creator->kyc_status)->toBe(KycStatus::Rejected);
    expect($creator->kyc_verified_at)->toBeNull();

    $completionAudit = AuditLog::query()->where('action', AuditAction::CreatorWizardKycCompleted)->count();
    expect($completionAudit)->toBe(0, 'Rejection does not fire the completion audit pair.');
});

it('records processing_error on the integration_events row when payload is malformed (defence-in-depth)', function (): void {
    $event = IntegrationEvent::query()->create([
        'provider' => 'kyc',
        'provider_event_id' => 'evt_malformed',
        'event_type' => 'verification.completed',
        // Stored payload that the parser will reject as missing event_id.
        'payload' => ['unexpected_shape' => true],
        'received_at' => now(),
    ]);

    $threw = false;
    try {
        (new ProcessKycWebhookJob($event->id))->handle(app(KycProvider::class), app(AuditLogger::class));
    } catch (Throwable) {
        $threw = true;
    }
    expect($threw)->toBeTrue('expected ProcessKycWebhookJob to rethrow on malformed payload');

    $event->refresh();
    expect($event->processing_error)->not->toBeNull();
});
