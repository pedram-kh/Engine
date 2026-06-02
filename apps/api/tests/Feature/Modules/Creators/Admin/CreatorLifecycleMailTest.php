<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Mail\CreatorApprovedMail;
use App\Modules\Creators\Mail\CreatorRejectedMail;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 4 Chunk 3 — Cluster 7: approval + rejection mailables (D-c3-11).
 *
 * Verified via Mail::fake() (dispatch + recipient + locale + reason),
 * NOT a real inbox. config/mail.php default is `log`; the real provider
 * is the deferred services.md item.
 */
function makeMailAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('dispatches CreatorApprovedMail to the creator in their locale on approve', function (): void {
    Mail::fake();

    $admin = makeMailAdmin();
    $creatorUser = User::factory()->create([
        'type' => UserType::Creator,
        'preferred_language' => 'pt',
    ]);
    $creator = CreatorFactory::new()->kycVerified()->createOne([
        'user_id' => $creatorUser->id,
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/approve", [])
        ->assertOk();

    Mail::assertQueued(CreatorApprovedMail::class, function (CreatorApprovedMail $mail) use ($creatorUser): bool {
        return $mail->hasTo($creatorUser->email) && $mail->locale === 'pt';
    });
    Mail::assertNotQueued(CreatorRejectedMail::class);
});

it('dispatches CreatorRejectedMail carrying the reason, in the creator locale, on reject', function (): void {
    Mail::fake();

    $admin = makeMailAdmin();
    $creatorUser = User::factory()->create([
        'type' => UserType::Creator,
        'preferred_language' => 'it',
    ]);
    $creator = CreatorFactory::new()->createOne([
        'user_id' => $creatorUser->id,
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    $reason = 'Portfolio insufficient for Tier 1 review.';

    $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/reject", [
            'rejection_reason' => $reason,
        ])
        ->assertOk();

    Mail::assertQueued(CreatorRejectedMail::class, function (CreatorRejectedMail $mail) use ($creatorUser, $reason): bool {
        return $mail->hasTo($creatorUser->email)
            && $mail->locale === 'it'
            && $mail->rejectionReason === $reason;
    });
    Mail::assertNotQueued(CreatorApprovedMail::class);
});

it('falls back to en locale when the creator has no preferred_language', function (): void {
    Mail::fake();

    $admin = makeMailAdmin();
    $creatorUser = User::factory()->create([
        'type' => UserType::Creator,
        'preferred_language' => '',
    ]);
    $creator = CreatorFactory::new()->kycVerified()->createOne([
        'user_id' => $creatorUser->id,
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/approve", [])
        ->assertOk();

    Mail::assertQueued(CreatorApprovedMail::class, function (CreatorApprovedMail $mail): bool {
        return $mail->locale === 'en';
    });
});

it('renders the rejection markdown view with the reason (content build, no real inbox)', function (): void {
    $mail = new CreatorRejectedMail(
        creatorDisplayName: 'Jordan',
        rejectionReason: 'Needs a stronger portfolio.',
    );

    $rendered = $mail->render();

    expect($rendered)->toContain('Needs a stronger portfolio.');
});
