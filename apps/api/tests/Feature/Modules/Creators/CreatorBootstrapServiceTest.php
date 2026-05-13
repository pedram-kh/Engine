<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Enums\VerificationLevel;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\CreatorBootstrapService;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\SignUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('CreatorBootstrapService creates a Creator row with bootstrap defaults', function (): void {
    $user = User::factory()->creator()->createOne([
        'preferred_language' => 'pt',
    ]);

    $creator = app(CreatorBootstrapService::class)->bootstrapForUser($user);

    expect($creator->user_id)->toBe($user->id)
        ->and($creator->verification_level)->toBe(VerificationLevel::Unverified)
        ->and($creator->application_status)->toBe(ApplicationStatus::Incomplete)
        ->and($creator->kyc_status)->toBe(KycStatus::None)
        ->and($creator->profile_completeness_score)->toBe(0)
        ->and($creator->tax_profile_complete)->toBeFalse()
        ->and($creator->payout_method_set)->toBeFalse()
        ->and($creator->primary_language)->toBe('pt')
        ->and($creator->display_name)->toBeNull()
        ->and($creator->country_code)->toBeNull();
});

it('CreatorBootstrapService is idempotent for an already-bootstrapped user', function (): void {
    $user = User::factory()->creator()->createOne();
    $first = app(CreatorBootstrapService::class)->bootstrapForUser($user);
    $second = app(CreatorBootstrapService::class)->bootstrapForUser($user);

    expect($first->id)->toBe($second->id)
        ->and(Creator::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('CreatorBootstrapService throws for non-creator user types', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();

    expect(fn () => app(CreatorBootstrapService::class)->bootstrapForUser($admin))
        ->toThrow(RuntimeException::class);
});

it('CreatorBootstrapService emits the creator.created audit row', function (): void {
    $user = User::factory()->creator()->createOne();
    $creator = app(CreatorBootstrapService::class)->bootstrapForUser($user);

    /** @var AuditLog|null $audit */
    $audit = AuditLog::query()
        ->where('action', AuditAction::CreatorCreated->value)
        ->where('subject_type', $creator->getMorphClass())
        ->where('subject_id', $creator->id)
        ->first();

    expect($audit)->not->toBeNull();
});

it('SignUpService transactionally creates User AND Creator', function (): void {
    /** @var SignUpService $service */
    $service = app(SignUpService::class);

    $request = Request::create('/api/v1/auth/sign-up', 'POST');

    $service->register([
        'email' => 'wizard.test@example.com',
        'name' => 'Wizard Test',
        'password' => 'this-is-a-strong-password-1!',
        'preferred_language' => 'en',
    ], $request);

    /** @var User $user */
    $user = User::query()->where('email', 'wizard.test@example.com')->firstOrFail();
    /** @var Creator $creator */
    $creator = Creator::query()->where('user_id', $user->id)->firstOrFail();

    expect($user->type)->toBe(UserType::Creator)
        ->and($creator->user_id)->toBe($user->id)
        ->and($creator->application_status)->toBe(ApplicationStatus::Incomplete)
        ->and($creator->primary_language)->toBe('en');
});

it('sign-up endpoint creates the Creator satellite atomically', function (): void {
    Mail::fake();

    $response = $this->postJson('/api/v1/auth/sign-up', [
        'email' => 'wizard.endpoint@example.com',
        'name' => 'Wizard Endpoint',
        'password' => 'this-is-a-strong-password-1!',
        'password_confirmation' => 'this-is-a-strong-password-1!',
        'preferred_language' => 'it',
    ]);

    $response->assertCreated();

    /** @var User $user */
    $user = User::query()->where('email', 'wizard.endpoint@example.com')->firstOrFail();
    /** @var Creator $creator */
    $creator = Creator::query()->where('user_id', $user->id)->firstOrFail();

    expect($creator->primary_language)->toBe('it')
        ->and($creator->application_status)->toBe(ApplicationStatus::Incomplete);
});
