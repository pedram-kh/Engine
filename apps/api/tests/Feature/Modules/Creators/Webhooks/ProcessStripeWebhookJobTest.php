<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Models\IntegrationEvent;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Database\Factories\CreatorPayoutMethodFactory;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Enums\PayoutStatus;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\Exceptions\FeatureDisabledException;
use App\Modules\Creators\Integrations\Mock\MockPaymentProvider;
use App\Modules\Creators\Integrations\Stubs\SkippedPaymentProvider;
use App\Modules\Creators\Jobs\ProcessStripeWebhookJob;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    app()->bind(PaymentProvider::class, MockPaymentProvider::class);
});

function makeStripeProcessCreator(): Creator
{
    $user = User::factory()->createOne();

    return CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);
}

function makeStripePayoutMethod(Creator $creator, string $accountId): CreatorPayoutMethod
{
    return CreatorPayoutMethodFactory::new()->createOne([
        'creator_id' => $creator->id,
        'provider' => 'stripe',
        'provider_account_id' => $accountId,
        'status' => PayoutStatus::Pending,
        'is_default' => true,
    ]);
}

function makeAccountUpdatedEvent(string $accountId, string $eventId, bool $verified = true): IntegrationEvent
{
    return IntegrationEvent::query()->create([
        'provider' => 'stripe',
        'provider_event_id' => $eventId,
        'event_type' => 'account.updated',
        'payload' => [
            'event_id' => $eventId,
            'event_type' => 'account.updated',
            'account_id' => $accountId,
            'charges_enabled' => $verified,
            'payouts_enabled' => $verified,
            'requirements_currently_due' => $verified ? [] : ['external_account'],
        ],
        'received_at' => now(),
    ]);
}

function runStripeJob(IntegrationEvent $event): void
{
    (new ProcessStripeWebhookJob($event->id))->handle(app(PaymentProvider::class), app(AuditLogger::class));
}

it('account.updated → verified flips payout status + verified_at, flips creators.payout_method_set, emits completion + envelope audits', function (): void {
    $creator = makeStripeProcessCreator();
    $payoutMethod = makeStripePayoutMethod($creator, 'acct_verify_ok');
    $event = makeAccountUpdatedEvent('acct_verify_ok', 'evt_verify_ok');

    runStripeJob($event);

    $payoutMethod->refresh();
    expect($payoutMethod->status)->toBe(PayoutStatus::Verified)
        ->and($payoutMethod->verified_at)->not->toBeNull();

    $creator->refresh();
    expect($creator->payout_method_set)->toBeTrue();

    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardPayoutCompleted)->count())->toBe(1);
    expect(AuditLog::query()->where('action', AuditAction::CreatorPayoutMethodUpdated)->count())->toBe(1);
    expect(AuditLog::query()->where('action', AuditAction::IntegrationWebhookProcessed)->count())->toBe(1);

    $event->refresh();
    expect($event->processed_at)->not->toBeNull();
});

it('account.updated NEVER touches creators.kyc_status — payout-KYC vs identity-KYC separation (D-c2-5)', function (): void {
    $creator = makeStripeProcessCreator();
    expect($creator->kyc_status)->toBe(KycStatus::None);

    makeStripePayoutMethod($creator, 'acct_no_kyc_touch');
    runStripeJob(makeAccountUpdatedEvent('acct_no_kyc_touch', 'evt_no_kyc_touch'));

    $creator->refresh();
    expect($creator->kyc_status)->toBe(
        KycStatus::None,
        'account.updated drives payout status only; conflating it with identity kyc_status corrupts the verification layer.',
    );
    expect($creator->kyc_verified_at)->toBeNull();
});

it('account.updated → requirements due maps to restricted, leaves payout_method_set false', function (): void {
    $creator = makeStripeProcessCreator();
    $payoutMethod = makeStripePayoutMethod($creator, 'acct_restricted');

    runStripeJob(makeAccountUpdatedEvent('acct_restricted', 'evt_restricted', verified: false));

    $payoutMethod->refresh();
    expect($payoutMethod->status)->toBe(PayoutStatus::Restricted)
        ->and($payoutMethod->verified_at)->toBeNull();

    $creator->refresh();
    expect($creator->payout_method_set)->toBeFalse();
    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardPayoutCompleted)->count())->toBe(0);
});

it('does NOT re-emit the completion audit on re-run for an already-verified payout method (#6 idempotency)', function (): void {
    $creator = makeStripeProcessCreator();
    makeStripePayoutMethod($creator, 'acct_idem');
    $event = makeAccountUpdatedEvent('acct_idem', 'evt_idem');

    runStripeJob($event);
    runStripeJob($event);

    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardPayoutCompleted)->count())
        ->toBe(1, 'Re-running the same job MUST NOT re-emit the completion audit.');
    expect(AuditLog::query()->where('action', AuditAction::CreatorPayoutMethodUpdated)->count())
        ->toBe(1, 'Re-running MUST NOT re-emit the payout-method update audit (status already terminal).');
});

it('records processing_error when no payout method matches the Stripe account id', function (): void {
    makeStripeProcessCreator();
    $event = makeAccountUpdatedEvent('acct_orphan', 'evt_orphan');

    runStripeJob($event);

    $event->refresh();
    expect($event->processing_error)->toContain('Unknown provider_account_id: acct_orphan');
    expect($event->processed_at)->not->toBeNull();
});

it('flag-off guarantee: SkippedPaymentProvider throws on the webhook methods — no Stripe call when the flag is off (§52)', function (): void {
    // The flag-off → Skipped resolution itself is pinned by
    // IntegrationProviderBindingsTest; here we pin that the flag-off
    // binding refuses to touch a vendor on the new webhook surface
    // (verifyWebhookSignature + parseWebhookEvent), so a stray call
    // with the flag off fails loudly instead of reaching Stripe.
    Feature::deactivate(CreatorPayoutMethodEnabled::NAME);

    $stub = new SkippedPaymentProvider;

    expect(fn () => $stub->verifyWebhookSignature('payload', 'sig'))
        ->toThrow(FeatureDisabledException::class);
    expect(fn () => $stub->parseWebhookEvent('payload'))
        ->toThrow(FeatureDisabledException::class);
});
