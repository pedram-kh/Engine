<?php

declare(strict_types=1);

use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Push\PushEligibility;
use Tests\Support\NotificationRouteKeys;
use Tests\TestCase;

uses(TestCase::class);

/**
 * AH-103 (C1.1) — the shared route-key contract is internally coherent and agrees
 * with the backend's own notion of "push-eligible". The other half (the SPA
 * registry covers exactly these types, and each destination needs exactly these
 * keys) is `templates.spec.ts`; the third half (the real emitters write the
 * `bag` keys) is `NotificationRouteKeysContractTest`.
 */
it('reads a non-empty contract (the suite is actually looking at something)', function (): void {
    expect(is_file(NotificationRouteKeys::path()))->toBeTrue()
        ->and(count(NotificationRouteKeys::types()))->toBeGreaterThan(15);
});

it('every contract type is a real backend notification type', function (): void {
    $unknown = array_values(array_filter(
        array_keys(NotificationRouteKeys::types()),
        static fn (string $type): bool => NotificationType::tryFrom($type) === null,
    ));

    expect($unknown)->toBe([]);
});

it('every key in the contract is one of the three route keys', function (): void {
    foreach (NotificationRouteKeys::types() as $type => $keys) {
        foreach ([...$keys['bag'], ...$keys['push']] as $key) {
            expect(in_array($key, NotificationRouteKeys::ROUTE_KEYS, true))->toBeTrue("{$type} uses an unknown route key {$key}");
        }
    }
});

it('push keys are a subset of the bag keys (a push can only carry what the row carries)', function (): void {
    foreach (NotificationRouteKeys::types() as $type => $keys) {
        expect(array_values(array_diff($keys['push'], $keys['bag'])))->toBe([], "{$type}: push key not in bag");
    }
});

it('every push-eligible type is in the contract, and only eligible types carry push keys', function (): void {
    $types = NotificationRouteKeys::types();

    foreach ([...PushEligibility::TOGGLEABLE, ...PushEligibility::ALWAYS_ON] as $eligible) {
        expect($types)->toHaveKey($eligible->value);
        // An eligible type forwards every key its row carries.
        expect($types[$eligible->value]['push'])->toBe($types[$eligible->value]['bag'], $eligible->value);
    }

    foreach ($types as $type => $keys) {
        $enum = NotificationType::from($type);
        if (! PushEligibility::isEligible($enum)) {
            expect($keys['push'])->toBe([], "{$type} never pushes, so it carries no push keys");
        }
    }
});
