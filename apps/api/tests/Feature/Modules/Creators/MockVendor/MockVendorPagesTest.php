<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Integrations\Mock\MockEsignProvider;
use App\Modules\Creators\Integrations\Mock\MockKycProvider;
use App\Modules\Creators\Integrations\Mock\MockPaymentProvider;
use App\Modules\Creators\Jobs\SimulateEsignWebhookJob;
use App\Modules\Creators\Jobs\SimulateKycWebhookJob;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Bus::fake();
});

function makeMockVendorCreator(): Creator
{
    $user = User::factory()->createOne();

    return CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);
}

it('renders the KYC mock-vendor Blade page when the session exists in cache', function (): void {
    $creator = makeMockVendorCreator();
    $sessionId = (new MockKycProvider)->initiateVerification($creator)->sessionId;

    $response = $this->get('/_mock-vendor/kyc/'.$sessionId);

    $response->assertStatus(200);
    expect($response->getContent())->toContain($sessionId);
});

it('renders 404 with a localised message when the KYC session is unknown', function (): void {
    $response = $this->get('/_mock-vendor/kyc/unknown_session');

    $response->assertStatus(404);
    expect($response->getContent())->toBe('Unknown or expired session.');
});

it('renders the localised KYC page in pt when the app locale is pt', function (): void {
    $creator = makeMockVendorCreator();
    $sessionId = (new MockKycProvider)->initiateVerification($creator)->sessionId;

    app()->setLocale('pt');
    $response = $this->get('/_mock-vendor/kyc/'.$sessionId);

    $response->assertStatus(200);
    expect($response->getContent())->toContain('Verificação KYC simulada');
});

it('renders the localised KYC page in it when the app locale is it', function (): void {
    $creator = makeMockVendorCreator();
    $sessionId = (new MockKycProvider)->initiateVerification($creator)->sessionId;

    app()->setLocale('it');
    $response = $this->get('/_mock-vendor/kyc/'.$sessionId);

    $response->assertStatus(200);
    expect($response->getContent())->toContain('Verifica KYC simulata');
});

it('completes the KYC mock with success → updates cache state + dispatches SimulateKycWebhookJob with verified outcome', function (): void {
    $creator = makeMockVendorCreator();
    $sessionId = (new MockKycProvider)->initiateVerification($creator)->sessionId;

    $response = $this->post('/_mock-vendor/kyc/'.$sessionId.'/complete', ['outcome' => 'success']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/api/v1/creators/me/wizard/kyc/return?session='.$sessionId);

    $cached = Cache::get(MockKycProvider::sessionCacheKey($sessionId));
    expect($cached['state'])->toBe('success');

    Bus::assertDispatched(SimulateKycWebhookJob::class, function (SimulateKycWebhookJob $job) use ($creator) {
        return $job->creatorUlid === $creator->ulid && $job->outcome === 'verified';
    });
});

it('completes the KYC mock with cancel → does NOT dispatch the simulate job', function (): void {
    $creator = makeMockVendorCreator();
    $sessionId = (new MockKycProvider)->initiateVerification($creator)->sessionId;

    $this->post('/_mock-vendor/kyc/'.$sessionId.'/complete', ['outcome' => 'cancel']);

    Bus::assertNotDispatched(SimulateKycWebhookJob::class);
    expect(Cache::get(MockKycProvider::sessionCacheKey($sessionId))['state'])->toBe('cancelled');
});

it('completes the eSign mock with success → dispatches SimulateEsignWebhookJob with signed outcome', function (): void {
    $creator = makeMockVendorCreator();
    $envelopeId = (new MockEsignProvider)->sendEnvelope($creator)->envelopeId;

    $response = $this->post('/_mock-vendor/esign/'.$envelopeId.'/complete', ['outcome' => 'success']);

    $response->assertRedirect();
    Bus::assertDispatched(SimulateEsignWebhookJob::class, function (SimulateEsignWebhookJob $job) use ($creator) {
        return $job->creatorUlid === $creator->ulid && $job->outcome === 'signed';
    });
});

it('completes the Stripe mock with success → updates cache; does NOT dispatch any webhook job (Sprint 3 Stripe = status-poll only)', function (): void {
    $creator = makeMockVendorCreator();
    $accountId = (new MockPaymentProvider)->createConnectedAccount($creator)->accountId;

    $response = $this->post('/_mock-vendor/stripe/'.$accountId.'/complete', ['outcome' => 'success']);

    $response->assertRedirect();
    expect(Cache::get(MockPaymentProvider::accountCacheKey($accountId))['state'])->toBe('complete');

    Bus::assertNothingDispatched();
});
