<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Database\Factories\CreatorPortfolioItemFactory;
use App\Modules\Creators\Database\Factories\CreatorSocialAccountFactory;
use App\Modules\Creators\Enums\EsignStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Enums\PayoutStatus;
use App\Modules\Creators\Enums\PortfolioProcessingStatus;
use App\Modules\Creators\Enums\SocialPlatform;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\AccountStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignEnvelopeResult;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignWebhookEvent;
use App\Modules\Creators\Integrations\DataTransferObjects\KycInitiationResult;
use App\Modules\Creators\Integrations\DataTransferObjects\KycWebhookEvent;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentsWebhookEvent;
use App\Modules\Creators\Jobs\ProcessPortfolioImageJob;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKycVerification;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use App\Modules\Creators\Models\CreatorSocialAccount;
use App\Modules\Creators\Models\CreatorTaxProfile;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Helper: bind a fake provider that returns a stable result object so the
 * wizard step endpoints exercise the real service layer without hitting
 * the Deferred*Provider stubs (which throw by design in Sprint 3 Chunk 1).
 */
function bindFakeProviders(): void
{
    app()->bind(KycProvider::class, fn (): KycProvider => new class implements KycProvider
    {
        public function initiateVerification(Creator $creator): KycInitiationResult
        {
            return new KycInitiationResult('sess-fake', 'https://kyc.example/start', '2026-12-31T00:00:00Z');
        }

        public function getVerificationStatus(Creator $creator): KycStatus
        {
            return KycStatus::Pending;
        }

        public function verifyWebhookSignature(string $payload, string $signature): bool
        {
            return true;
        }

        public function parseWebhookEvent(string $payload): KycWebhookEvent
        {
            return new KycWebhookEvent('evt_fake', 'verification.completed', null, KycStatus::Verified, []);
        }
    });

    app()->bind(PaymentProvider::class, fn (): PaymentProvider => new class implements PaymentProvider
    {
        public function createConnectedAccount(Creator $creator): PaymentAccountResult
        {
            return new PaymentAccountResult('acct_fake', 'https://stripe.example/onboarding', '2026-12-31T00:00:00Z');
        }

        public function getAccountStatus(Creator $creator): AccountStatus
        {
            return new AccountStatus(false, false, false, []);
        }

        public function verifyWebhookSignature(string $payload, string $signature): bool
        {
            return true;
        }

        public function parseWebhookEvent(string $payload): PaymentsWebhookEvent
        {
            return new PaymentsWebhookEvent('evt_fake', 'account.updated', 'acct_fake', PayoutStatus::Verified, true, true, []);
        }
    });

    app()->bind(EsignProvider::class, fn (): EsignProvider => new class implements EsignProvider
    {
        public function sendEnvelope(Creator $creator): EsignEnvelopeResult
        {
            return new EsignEnvelopeResult('env-fake', 'https://esign.example/sign', '2026-12-31T00:00:00Z');
        }

        public function getEnvelopeStatus(Creator $creator): EsignStatus
        {
            return EsignStatus::Sent;
        }

        public function verifyWebhookSignature(string $payload, string $signature): bool
        {
            return true;
        }

        public function parseWebhookEvent(string $payload): EsignWebhookEvent
        {
            return new EsignWebhookEvent('evt_fake', 'envelope.signed', null, EsignStatus::Signed, []);
        }
    });
}

beforeEach(function (): void {
    bindFakeProviders();

    // Sprint 3 Chunk 2 sub-step 9 — these endpoints exercise the
    // flag-ON happy path. The flag-OFF skip-path is asserted in
    // CreatorWizardFlagOffTest. Activating here keeps the
    // chunk-1-era tests intact under the new gating.
    Feature::activate(KycVerificationEnabled::NAME);
    Feature::activate(ContractSigningEnabled::NAME);
    Feature::activate(CreatorPayoutMethodEnabled::NAME);
});

it('PATCH /wizard/profile updates fields and recomputes completeness when all profile fields land', function (): void {
    $user = User::factory()->create();
    // Bootstrap state mirrors what CreatorBootstrapService produces
    // on sign-up — every wizard-related field is null. We then
    // pre-seed avatar_path because the avatar is uploaded via a
    // separate endpoint, not via this PATCH.
    $creator = CreatorFactory::new()->bootstrap()->createOne([
        'user_id' => $user->id,
        'avatar_path' => 'creators/seed/avatar/x.jpg',
    ]);

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/profile', [
            'display_name' => 'Catalyst',
            'country_code' => 'IT',
            'primary_language' => 'en',
            'categories' => ['lifestyle', 'music'],
        ]);

    $response->assertOk();
    $creator->refresh();
    expect($creator->display_name)->toBe('Catalyst')
        ->and($creator->profile_completeness_score)->toBeGreaterThan(0);
});

it('PATCH /wizard/profile validates the categories enum', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/profile', [
            'categories' => ['nonsense'],
        ])
        ->assertStatus(422);
});

it('PATCH /wizard/profile emits CreatorWizardProfileCompleted exactly once on first completion (#6 idempotent)', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->bootstrap()->createOne([
        'user_id' => $user->id,
        'avatar_path' => 'creators/seed/avatar/x.jpg',
    ]);

    $payload = [
        'display_name' => 'Idem',
        'country_code' => 'IT',
        'primary_language' => 'en',
        'categories' => ['lifestyle'],
    ];

    $this->actingAs($user)->patchJson('/api/v1/creators/me/wizard/profile', $payload)->assertOk();
    $this->actingAs($user)->patchJson('/api/v1/creators/me/wizard/profile', $payload)->assertOk();

    expect(AuditLog::query()
        ->where('action', AuditAction::CreatorWizardProfileCompleted->value)
        ->where('subject_id', $creator->id)
        ->count())->toBe(1);
});

it('POST /wizard/social creates a social account and emits the audit row', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/social', [
            'platform' => 'instagram',
            'handle' => '@catalyst',
            'profile_url' => 'https://instagram.com/catalyst',
        ])
        ->assertOk();

    expect(CreatorSocialAccount::where('creator_id', $creator->id)->count())->toBe(1);
    expect(AuditLog::query()
        ->where('action', AuditAction::CreatorWizardSocialCompleted->value)
        ->where('subject_id', $creator->id)
        ->count())->toBe(1);
});

it('POST /wizard/social strips a leading @ and stores the bare handle', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/social', [
            'platform' => 'instagram',
            'handle' => '@Catalyst_99',
            'profile_url' => 'https://instagram.com/Catalyst_99',
        ])
        ->assertOk();

    expect(CreatorSocialAccount::where('creator_id', $creator->id)->value('handle'))
        ->toBe('Catalyst_99');
});

it('POST /wizard/social rejects a handle that is a URL or has spaces (per-field 422)', function (string $handle): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/social', [
            'platform' => 'youtube',
            'handle' => $handle,
            'profile_url' => 'https://youtube.com/@x',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/handle');
})->with([
    'pasted URL' => ['https://youtube.com/@ThePrimeTimeagen'],
    'has spaces' => ['my handle'],
    'too short' => ['a'],
]);

it('DELETE /wizard/social/{platform} disconnects the account and is idempotent', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);
    CreatorSocialAccountFactory::new()->for($creator)->platform(SocialPlatform::Instagram)->create();

    $this->actingAs($user)
        ->deleteJson('/api/v1/creators/me/wizard/social/instagram')
        ->assertOk();

    expect(CreatorSocialAccount::where('creator_id', $creator->id)->count())->toBe(0);

    // Idempotent: deleting an already-removed platform is still 200.
    $this->actingAs($user)
        ->deleteJson('/api/v1/creators/me/wizard/social/instagram')
        ->assertOk();
});

it('DELETE /wizard/social hard-deletes so the same platform can be reconnected', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);
    CreatorSocialAccountFactory::new()->for($creator)->platform(SocialPlatform::Instagram)->create();

    $this->actingAs($user)
        ->deleteJson('/api/v1/creators/me/wizard/social/instagram')
        ->assertOk();

    // Reconnect the SAME platform — must not collide on the unique
    // (creator_id, platform) index (regression guard for the soft-delete trap).
    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/social', [
            'platform' => 'instagram',
            'handle' => 'fresh_handle',
            'profile_url' => 'https://instagram.com/fresh_handle',
        ])
        ->assertOk();

    expect(CreatorSocialAccount::where('creator_id', $creator->id)->count())->toBe(1);
});

it('DELETE /wizard/social promotes a new primary when the removed account was primary', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);
    CreatorSocialAccountFactory::new()->for($creator)->platform(SocialPlatform::Instagram)->primary()->create();
    CreatorSocialAccountFactory::new()->for($creator)->platform(SocialPlatform::TikTok)->create();

    $this->actingAs($user)
        ->deleteJson('/api/v1/creators/me/wizard/social/instagram')
        ->assertOk();

    expect(CreatorSocialAccount::where('creator_id', $creator->id)->count())->toBe(1);

    $promoted = CreatorSocialAccount::where('creator_id', $creator->id)->sole();
    expect($promoted->platform)->toBe(SocialPlatform::TikTok)
        ->and($promoted->is_primary)->toBeTrue();
});

it('DELETE /wizard/social/{platform} returns 422 for an unknown platform', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson('/api/v1/creators/me/wizard/social/myspace')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'creator.social.invalid_platform');
});

it('POST /portfolio/videos/init returns the exact keys the SPA reads (contract guard)', function (): void {
    // Regression guard: the SPA reads `init.data.upload_url`. A backend
    // that returns `url` instead leaves upload_url undefined and silently
    // breaks browser video uploads. The frontend mock and the backend
    // test previously validated divergent shapes; this pins the real
    // endpoint response to the published PortfolioVideoInitResponse type.
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/portfolio/videos/init', [
            'mime_type' => 'video/mp4',
            'declared_bytes' => 50 * 1024 * 1024,
        ])
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['upload_url', 'upload_id', 'storage_path', 'expires_at'],
        ]);
});

it('POST /portfolio/videos/complete persists a client-captured poster as the thumbnail', function (): void {
    Storage::fake('media');
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    // Simulate the presigned PUT having already landed the video object.
    $uploadId = "creators/{$creator->ulid}/portfolio/01POSTERVID0000000000000.mp4";
    Storage::disk('media')->put($uploadId, 'fake video bytes');

    $this->actingAs($user)
        ->post('/api/v1/creators/me/portfolio/videos/complete', [
            'upload_id' => $uploadId,
            'mime_type' => 'video/mp4',
            'size_bytes' => 5_000_000,
            'thumbnail' => UploadedFile::fake()->image('poster.jpg', 640, 360),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'video');

    $item = CreatorPortfolioItem::query()->where('creator_id', $creator->id)->sole();
    expect($item->thumbnail_path)->not->toBeNull()
        ->and($item->s3_path)->toBe($uploadId);
});

it('POST /portfolio/videos/complete works without a poster (thumbnail stays null)', function (): void {
    Storage::fake('media');
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $uploadId = "creators/{$creator->ulid}/portfolio/01NOPOSTERVID000000000000.mp4";
    Storage::disk('media')->put($uploadId, 'fake video bytes');

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/portfolio/videos/complete', [
            'upload_id' => $uploadId,
            'mime_type' => 'video/mp4',
            'size_bytes' => 5_000_000,
        ])
        ->assertCreated();

    $item = CreatorPortfolioItem::query()->where('creator_id', $creator->id)->sole();
    expect($item->thumbnail_path)->toBeNull();
});

// ── AH-004: large-image presigned upload + link + cleanup ──────────────────

it('POST /portfolio/images/init returns a presigned upload payload', function (): void {
    Storage::fake('media');
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/portfolio/images/init', [
            'mime_type' => 'image/jpeg',
            'declared_bytes' => 120 * 1024 * 1024,
        ])
        ->assertOk()
        ->assertJsonStructure(['data' => ['upload_url', 'upload_id', 'storage_path', 'expires_at', 'max_bytes']]);
});

it('POST /portfolio/images/init rejects a non-image mime type', function (): void {
    Storage::fake('media');
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/portfolio/images/init', [
            'mime_type' => 'video/mp4',
            'declared_bytes' => 1000,
        ])
        ->assertStatus(422);
});

it('POST /portfolio/images/complete creates a PROCESSING item and dispatches the sanitiser job', function (): void {
    Queue::fake();
    Storage::fake('media');
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    // Simulate the presigned PUT having already landed the raw image.
    $uploadId = "creators/{$creator->ulid}/portfolio/01RAWIMG00000000000000000.jpg";
    Storage::disk('media')->put($uploadId, 'fake raw image bytes');

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/portfolio/images/complete', [
            'upload_id' => $uploadId,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 120 * 1024 * 1024,
            'title' => 'Big shot',
        ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'image')
        ->assertJsonPath('data.processing_status', 'processing');

    $item = CreatorPortfolioItem::query()->where('creator_id', $creator->id)->sole();
    expect($item->processing_status)->toBe(PortfolioProcessingStatus::Processing);

    Queue::assertPushed(ProcessPortfolioImageJob::class, fn (ProcessPortfolioImageJob $job): bool => $job->portfolioItemId === $item->id);
});

it('POST /portfolio/links creates a READY link for an http(s) URL', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/portfolio/links', [
            'external_url' => 'https://example.com/my-reel',
            'title' => 'My reel',
        ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'link')
        ->assertJsonPath('data.processing_status', 'ready')
        ->assertJsonPath('data.external_url', 'https://example.com/my-reel');

    expect(CreatorPortfolioItem::query()->where('creator_id', $creator->id)->count())->toBe(1);
});

it('POST /portfolio/links rejects javascript:, data:, and non-http schemes (XSS guard)', function (string $url): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/portfolio/links', ['external_url' => $url])
        ->assertStatus(422);
})->with([
    'javascript scheme' => ['javascript:alert(1)'],
    'data scheme' => ['data:text/html;base64,PHNjcmlwdD4='],
    'ftp scheme' => ['ftp://example.com/file'],
    'not a url' => ['not-a-url'],
]);

it('POST /portfolio/links rejects an over-length URL', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/portfolio/links', [
            'external_url' => 'https://example.com/'.str_repeat('a', 2100),
        ])
        ->assertStatus(422);
});

it('DELETE /portfolio/{item} cleans up the raw S3 object of a FAILED item (no orphaned storage)', function (): void {
    Storage::fake('media');
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $rawPath = "creators/{$creator->ulid}/portfolio/01FAILEDRAW0000000000000.jpg";
    Storage::disk('media')->put($rawPath, 'raw exif-bearing bytes');

    $item = CreatorPortfolioItemFactory::new()->failed()->createOne([
        'creator_id' => $creator->id,
        's3_path' => $rawPath,
    ]);

    Storage::disk('media')->assertExists($rawPath);

    $this->actingAs($user)
        ->deleteJson("/api/v1/creators/me/portfolio/{$item->ulid}")
        ->assertNoContent();

    Storage::disk('media')->assertMissing($rawPath);
});

it('POST /wizard/kyc returns the hosted-flow URL and persists a KYC row', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/creators/me/wizard/kyc');

    $response->assertOk()
        ->assertJsonPath('data.session_id', 'sess-fake')
        ->assertJsonPath('data.hosted_flow_url', 'https://kyc.example/start');

    expect(CreatorKycVerification::where('creator_id', $creator->id)->count())->toBe(1);
    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Pending);
});

it('PATCH /wizard/tax persists the encrypted tax profile', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/tax', [
            'tax_form_type' => 'eu_self_employed',
            'legal_name' => 'Catalyst Srl',
            'tax_id' => 'IT12345678901',
            'address' => [
                'country_code' => 'it',
                'city' => 'Milan',
                'postal_code' => '20121',
                'street' => 'Via Roma 1',
            ],
        ])
        ->assertOk();

    $profile = CreatorTaxProfile::where('creator_id', $creator->id)->firstOrFail();
    expect($profile->legal_name)->toBe('Catalyst Srl')
        ->and($profile->tax_id_country)->toBe('IT')
        ->and($creator->refresh()->tax_profile_complete)->toBeTrue();
});

it('POST /wizard/payout returns the onboarding URL and persists the payout method', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/creators/me/wizard/payout');

    $response->assertOk()
        ->assertJsonPath('data.account_id', 'acct_fake')
        ->assertJsonPath('data.onboarding_url', 'https://stripe.example/onboarding');

    expect(CreatorPayoutMethod::where('creator_id', $creator->id)->count())->toBe(1);
});

it('POST /wizard/contract returns the signing URL', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/creators/me/wizard/contract');

    $response->assertOk()
        ->assertJsonPath('data.envelope_id', 'env-fake')
        ->assertJsonPath('data.signing_url', 'https://esign.example/sign');
});

it('POST /wizard/submit refuses to submit when wizard steps remain incomplete', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/submit')
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'creator.wizard.incomplete');
});

it('POST /wizard/submit transitions to pending when every step is complete', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne([
        'user_id' => $user->id,
        'display_name' => 'Done',
        'country_code' => 'IT',
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
        'kyc_status' => KycStatus::Verified,
        'tax_profile_complete' => true,
        'payout_method_set' => true,
        'signed_master_contract_id' => 1,
    ]);

    CreatorSocialAccountFactory::new()->for($creator)->create();
    CreatorPortfolioItemFactory::new()->for($creator)->create();

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/submit')
        ->assertOk()
        ->assertJsonPath('data.attributes.application_status', 'pending');

    expect(AuditLog::query()
        ->where('action', AuditAction::CreatorSubmitted->value)
        ->where('subject_id', $creator->id)
        ->count())->toBe(1);
});
