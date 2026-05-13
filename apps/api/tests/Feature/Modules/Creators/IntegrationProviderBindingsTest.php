<?php

declare(strict_types=1);

use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\Exceptions\ProviderNotBoundException;
use App\Modules\Creators\Integrations\Stubs\DeferredEsignProvider;
use App\Modules\Creators\Integrations\Stubs\DeferredKycProvider;
use App\Modules\Creators\Integrations\Stubs\DeferredPaymentProvider;
use App\Modules\Creators\Models\Creator;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Sprint 3 Chunk 1 — provider-contract bindings + Deferred-stub regression
|--------------------------------------------------------------------------
|
| D-pause-11: Sprint 3 Chunk 1 ships only the contract interfaces and
| Deferred stubs. Chunk 2 swaps the bindings to Mock implementations.
|
| These tests pin three invariants:
|
|   1. Each contract resolves out of the container to its Deferred stub.
|   2. Each Deferred stub throws ProviderNotBoundException when called.
|   3. Each contract surface matches the Sprint-3 subset (single method).
|
| Defense-in-depth coverage (#40): if Chunk 2's binding swap forgets to
| update one of the three bindings, the provider still throws — we never
| silently fall back to a no-op.
*/

it('KycProvider resolves to DeferredKycProvider', function (): void {
    expect(app(KycProvider::class))->toBeInstanceOf(DeferredKycProvider::class);
});

it('EsignProvider resolves to DeferredEsignProvider', function (): void {
    expect(app(EsignProvider::class))->toBeInstanceOf(DeferredEsignProvider::class);
});

it('PaymentProvider resolves to DeferredPaymentProvider', function (): void {
    expect(app(PaymentProvider::class))->toBeInstanceOf(DeferredPaymentProvider::class);
});

it('DeferredKycProvider throws ProviderNotBoundException', function (): void {
    $stub = new DeferredKycProvider;
    $stub->initiateVerification(new Creator);
})->throws(ProviderNotBoundException::class, "Integration provider 'KycProvider' is not bound");

it('DeferredEsignProvider throws ProviderNotBoundException', function (): void {
    $stub = new DeferredEsignProvider;
    $stub->sendEnvelope(new Creator);
})->throws(ProviderNotBoundException::class, "Integration provider 'EsignProvider' is not bound");

it('DeferredPaymentProvider throws ProviderNotBoundException', function (): void {
    $stub = new DeferredPaymentProvider;
    $stub->createConnectedAccount(new Creator);
})->throws(ProviderNotBoundException::class, "Integration provider 'PaymentProvider' is not bound");

it('the three contracts each define exactly one Sprint-3 method', function (): void {
    $expectedMethods = [
        KycProvider::class => ['initiateVerification'],
        EsignProvider::class => ['sendEnvelope'],
        PaymentProvider::class => ['createConnectedAccount'],
    ];

    foreach ($expectedMethods as $contract => $methods) {
        $reflection = new ReflectionClass($contract);
        $actual = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(fn (ReflectionMethod $m): string => $m->getName())
            ->sort()
            ->values()
            ->all();

        sort($methods);

        expect($actual)->toBe($methods, "{$contract} should expose exactly the Sprint-3 subset.");
    }
});

it('Sprint-3-subset docblock is present on each contract for Chunk-2 read pass (#34)', function (): void {
    $contracts = [
        KycProvider::class,
        EsignProvider::class,
        PaymentProvider::class,
    ];

    foreach ($contracts as $contract) {
        $doc = (new ReflectionClass($contract))->getDocComment();
        expect($doc)->toBeString()
            ->and($doc)->toContain('Sprint 3 subset')
            ->and($doc)->toContain('Future-extension methods');
    }
});
