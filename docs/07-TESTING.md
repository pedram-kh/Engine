# 07 — Testing Strategy

> **Status: Always active reference. Defines the testing requirements for every feature, every PR, every release. No code ships without tests.**

This is the single most important document for keeping velocity across four phases. Without it, by Phase 3, regression rate destroys the team. With it, you keep shipping confidently for years.

---

## 1. The testing philosophy

Three principles:

1. **Tests are the contract that code respects in the future.** A feature without tests is a feature you'll be afraid to change in 6 months.
2. **Cover behavior, not implementation.** Tests should still pass after a refactor that doesn't change behavior. If they don't, they were testing the wrong thing.
3. **Cost-aware coverage.** Some code is worth 100% coverage (auth, payments, audit, authorization). Some is fine at 70%. Aim coverage where bugs hurt most.

---

## 2. The test pyramid (target distribution)

```
                    ▲
                   ╱ ╲      E2E (Playwright)
                  ╱   ╲     ~5% of tests, ~15 critical paths
                 ╱─────╲
                ╱       ╲   Component / integration
               ╱         ╲  ~25% of tests
              ╱───────────╲
             ╱             ╲
            ╱     Unit      ╲ ~70% of tests
           ╱_________________╲
```

- **Unit tests:** fast, isolated, lots of them. Drive most coverage.
- **Component / integration tests:** test things together (Vue components with a real router, Laravel feature tests with a real database).
- **E2E tests:** few but critical. They test full user journeys across the system.

---

## 3. Backend testing (Pest)

### 3.1 Test types

#### Unit tests (`tests/Unit/`)

- Test pure logic in isolation: services, value objects, calculators, validators.
- No database. No HTTP. No filesystem. Mock dependencies.
- Fast — entire unit suite should run in seconds.

```php
test('Money correctly adds with same currency', function () {
    $a = Money::fromMinorUnits(1000, 'EUR');
    $b = Money::fromMinorUnits(500, 'EUR');
    expect($a->add($b)->minorUnits())->toBe(1500);
});

test('Money refuses to add different currencies', function () {
    $a = Money::fromMinorUnits(1000, 'EUR');
    $b = Money::fromMinorUnits(500, 'GBP');
    expect(fn() => $a->add($b))
        ->toThrow(IncompatibleCurrenciesException::class);
});
```

#### Feature tests (`tests/Feature/`)

- Test API endpoints end-to-end (HTTP layer → DB → response).
- Use `RefreshDatabase` trait for test isolation.
- Use factories for test data.
- Assert on response shape, status, side effects (DB state, dispatched events, queued jobs).

```php
test('agency admin can create a campaign for their brand', function () {
    $agency = Agency::factory()->create();
    $brand = Brand::factory()->for($agency)->create();
    $admin = User::factory()->agencyAdmin($agency)->create();

    $response = actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/campaigns", [
            'data' => [
                'attributes' => [
                    'name' => 'Summer 2026',
                    'objective' => 'awareness',
                    'budget_minor_units' => 5_000_000,
                    'budget_currency' => 'EUR',
                ],
                'relationships' => [
                    'brand' => ['data' => ['id' => $brand->ulid, 'type' => 'brand']],
                ],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Summer 2026');

    $this->assertDatabaseHas('campaigns', [
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'name' => 'Summer 2026',
    ]);

    Event::assertDispatched(CampaignCreated::class);
});
```

#### Policy tests (`tests/Feature/Policies/` or co-located)

- Every policy class has a dedicated test class.
- Tests cover: owner can perform, non-owner can't, cross-tenant can't, suspended user can't.

```php
test('CampaignPolicy::create denies cross-tenant', function () {
    $agencyA = Agency::factory()->create();
    $agencyB = Agency::factory()->create();
    $userInB = User::factory()->agencyAdmin($agencyB)->create();

    $policy = new CampaignPolicy();
    expect($policy->create($userInB, $agencyA))->toBeFalse();
});
```

#### Tenancy tests

- Every tenant-scoped model has tests confirming that:
  - Cross-tenant queries return nothing
  - Cross-tenant `find()` returns null (not the resource)
  - Cross-tenant API requests return 404 (not 403, to avoid leaking existence)

#### Audit tests

- Every privileged action that should emit an audit entry has a test confirming it does.

```php
test('approving a creator emits an audit entry', function () {
    $admin = User::factory()->platformAdmin()->create();
    $creator = Creator::factory()->pending()->create();

    actingAs($admin)
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/approve", [
            'reason' => 'Profile meets standards',
        ])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $admin->id,
        'action' => 'creator.approved',
        'subject_type' => 'Creator',
        'subject_id' => $creator->id,
    ]);
});
```

#### Event tests

- Every Listener has tests confirming it responds correctly to its event.
- `Event::fake()` and `Event::assertDispatched(...)` for assertions.
- For listeners that produce side effects, test the side effect directly.

#### Job tests

- Every queued job has tests for: success, retryable failure, permanent failure.
- `Queue::fake()` for assertions.

#### Webhook tests

- Every webhook handler has tests for: valid signature, invalid signature, idempotency (duplicate event), unknown event type, malformed payload.

### 3.2 Test data (factories)

- Every model has a factory.
- Factories produce minimum-valid data by default.
- Factories support traits for common variations: `User::factory()->creator()`, `User::factory()->agencyAdmin($agency)`.
- States compose: `Campaign::factory()->active()->withBudget(50_000)->create()`.
- Avoid hard-coded test data in test files; centralize in factories.

### 3.3 Static analysis vs tests

Static analysis (Larastan) and tests are complementary. Static analysis catches type errors and many logic bugs at compile time. Tests cover behavior. Both run in CI; both must pass.

### 3.4 Coverage requirements

| Module / area                           | Minimum line coverage |
| --------------------------------------- | --------------------- |
| `app/Modules/Identity/` (auth)          | **100%**              |
| `app/Modules/Payments/`                 | **100%**              |
| `app/Modules/Audit/`                    | **100%**              |
| `app/Modules/Authorization/` (policies) | **100%**              |
| Other modules                           | 85%                   |
| Overall codebase                        | 80% (CI threshold)    |

PR fails if coverage drops below threshold.

---

## 4. Frontend testing (Vitest + Playwright)

### 4.1 Test types

#### Unit tests (Vitest)

- Test pure logic: composables, utilities, validators.
- Test Pinia stores in isolation.

```ts
// tests/composables/useCampaignValidation.spec.ts
import { describe, it, expect } from 'vitest'
import { validateCampaignBrief } from '@/modules/campaigns/composables/useCampaignValidation'

describe('validateCampaignBrief', () => {
  it('rejects briefs without deliverables', () => {
    const result = validateCampaignBrief({ deliverables: [] })
    expect(result.isValid).toBe(false)
    expect(result.errors).toContain('deliverables_required')
  })
})
```

#### Component tests (Vitest with Vue Test Utils)

- Render a component in isolation.
- Test props, emits, slots, user interactions.

```ts
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import CampaignCard from '@/modules/campaigns/components/CampaignCard.vue'

describe('CampaignCard', () => {
  it('emits select when clicked', async () => {
    const campaign = { ulid: '01HQ...', name: 'Test', status: 'active' }
    const wrapper = mount(CampaignCard, { props: { campaign } })

    await wrapper.trigger('click')

    expect(wrapper.emitted('select')).toBeTruthy()
    expect(wrapper.emitted('select')![0]).toEqual([campaign])
  })

  it('shows overdue indicator for past end dates', () => {
    const campaign = { ulid: '01HQ...', name: 'Test', endsAt: '2020-01-01' }
    const wrapper = mount(CampaignCard, { props: { campaign } })

    expect(wrapper.find('[data-test="overdue-indicator"]').exists()).toBe(true)
  })
})
```

Component tests don't run a real backend. They mock API calls via the API client.

#### Integration tests (Vitest)

- Render a small tree of components together (a form with its inputs and validation).
- Test that pieces wire up correctly.

#### E2E tests (Playwright)

- Real browser, real backend (test instance), real database (test database).
- Cover critical user journeys end-to-end.
- Slower but very high confidence.

```ts
import { test, expect } from '@playwright/test'

test('creator can complete onboarding', async ({ page }) => {
  await page.goto('/sign-up')
  await page.fill('[data-test="email"]', 'test@example.com')
  await page.fill('[data-test="password"]', 'a-secure-pw-12chars')
  await page.click('[data-test="sign-up-submit"]')

  // Email verification simulated via test endpoint
  await page.goto(await getVerificationLink('test@example.com'))

  // Continue onboarding
  await page.fill('[data-test="display-name"]', 'Test Creator')
  await page.fill('[data-test="bio"]', 'I make videos.')
  // ... etc.

  await expect(page).toHaveURL('/onboarding/complete')
})
```

### 4.2 Critical user journeys (full E2E coverage required)

These journeys must have green Playwright tests at all times. Breaking one blocks deploy.

**Phase 1 critical paths:**

1. Creator self-onboarding: signup → email verify → profile build → KYC → master contract sign → approval-pending state
2. Creator profile rejection: admin rejects → creator sees feedback → creator updates → resubmits
3. Creator profile approval: admin approves → creator sees active state
4. Creator availability: add block → block visible on calendar → conflicts surface in matching
5. Agency admin creates a brand
6. Agency admin invites another team member with a role
7. Agency creates a campaign for a brand
8. Agency invites creators in bulk to a campaign
9. Creator accepts an assignment
10. Creator submits a draft
11. Agency reviews and approves a draft
12. Agency requests a revision
13. Creator marks content as posted
14. System verifies post via social API (test mode)
15. Agency releases payment; creator receives payout (Stripe test mode)
16. Agency blacklists a creator with reason
17. Admin impersonates an agency user
18. User initiates GDPR export, downloads result
19. User initiates 2FA enrollment, completes flow
20. Failed login lockout after threshold

Each becomes a Playwright spec. The full suite runs against staging on every deploy; subsets run on every PR.

### 4.3 Coverage requirements

| Area           | Minimum coverage   |
| -------------- | ------------------ |
| `packages/ui/` | 90%                |
| Stores (Pinia) | 90%                |
| Composables    | 85%                |
| Auth flows     | 100%               |
| Payment flows  | 100%               |
| Overall        | 80% (CI threshold) |

### 4.4 Visual regression (deferred)

Phase 1 does not include automated visual regression testing. Phase 2 adds it via Chromatic or Playwright's screenshot comparison.

---

## 5. Testing the database

### 5.1 Migration tests

Every migration has a test:

```php
test('migration adds objective column to campaigns', function () {
    expect(Schema::hasColumn('campaigns', 'objective'))->toBeTrue();
});
```

Run after the test database is migrated. Failing migration tests block deploys.

### 5.2 Migration smoke tests on production-like data

- Phase 1: a script that runs migrations against a snapshot of staging data before they hit production.
- Phase 2: automated in CI — every migration runs against a recent snapshot.

### 5.3 Rollback tests

```php
test('migration is reversible', function () {
    Artisan::call('migrate', ['--path' => 'database/migrations/...']);
    Artisan::call('migrate:rollback', ['--step' => 1]);
    expect(Schema::hasColumn('campaigns', 'new_column'))->toBeFalse();
});
```

### 5.4 Backwards compatibility tests

For migrations that change existing tables:

- The old code reading the new schema must not break.
- The new code reading the old schema must not break.
- Tests cover both directions during the expand/migrate/contract phases.

See `08-DATABASE-EVOLUTION.md` for the full strategy.

---

## 6. Testing third-party integrations

### 6.1 Mock providers

- Every integration has a `Mock{Vendor}Provider` adapter.
- Mocks return deterministic data.
- Mocks honor the same contract as the real provider.
- Tests use mocks; real providers are NEVER hit during automated testing.

### 6.2 Sandbox environments for staging

Where vendors offer sandboxes (Stripe test mode, KYC sandbox accounts, etc.), staging uses them. E2E tests on staging exercise the full sandbox flow.

### 6.3 Webhook simulation

- A console command `php artisan integrations:fire-webhook stripe charge.succeeded` triggers a fake webhook for local development.
- Tests simulate webhooks via direct HTTP calls to the webhook endpoint with valid (test) signatures.

### 6.4 Contract tests

Each adapter has a contract test ensuring it implements the interface correctly:

```php
test('StripePaymentProvider conforms to contract', function () {
    expect(StripePaymentProvider::class)
        ->toImplement(PaymentProviderContract::class);
});
```

---

## 7. Performance testing

### 7.1 Phase 1 baseline

- Backend response time monitored via Laravel Telescope in dev (alerts on >500ms).
- N+1 query detection via Telescope.
- Frontend Lighthouse score monitored in staging.

### 7.2 Phase 2 expansion

- Load testing via k6 or Artillery for critical endpoints.
- Defined performance budgets (see `00-MASTER-ARCHITECTURE.md` § 16).
- CI gate on regression beyond budget.

### 7.3 Phase 3+ continuous

- Production performance dashboard.
- p95 / p99 SLOs per endpoint.
- Alerting on regression.

---

## 8. Security testing

### 8.1 Static analysis

- **Larastan** at level 8 — catches many logic bugs.
- **Semgrep** rules for common vulnerabilities (SQL injection, XSS, IDOR patterns).
- **Snyk** or GitHub security alerts for dependency CVEs.

### 8.2 Dynamic testing

- Phase 2: OWASP ZAP scan in CI before deploy to production.
- Phase 2: penetration testing annually.
- Phase 3: bug bounty program.

### 8.3 Auth and authorization tests

Every endpoint has tests for:

- Unauthenticated request → 401
- Authenticated but unauthorized → 403 or 404
- Authenticated and authorized → 200 / appropriate success
- Authenticated as suspended user → 423

---

## 9. CI pipeline

### 9.1 On every PR

```
1. Install dependencies (cached)
2. Lint (Pint, Prettier, ESLint)
3. Type check (Larastan, tsc)
4. Backend unit tests
5. Backend feature tests
6. Frontend unit tests
7. Frontend component tests
8. Build SPAs
9. Critical-path E2E tests (subset, faster)
10. Security scans (Semgrep, dependency audit, secrets scan)
11. Coverage report
12. Migration smoke test on staging snapshot
```

PR fails on any failure or coverage drop.

### 9.2 On merge to main

Same as PR + deploy to staging.

### 9.3 On staging deploy

Full E2E suite runs against staging. If any critical path fails, the deploy is rolled back (or staging is marked broken pending fix; production deploy blocked).

### 9.4 On production deploy (manual gate)

- All staging tests must be green.
- Migration plan reviewed (see `08-DATABASE-EVOLUTION.md`).
- Manual approval required.
- Deploy in a controlled window (not Friday afternoon).

---

## 10. Test naming conventions

### Backend (Pest)

- File names mirror source: `app/Modules/Campaigns/Services/CampaignService.php` → `tests/Unit/Modules/Campaigns/Services/CampaignServiceTest.php`.
- Test descriptions read like specifications:

```php
test('agency admin can create a campaign for their brand', function () { ... });
test('non-admin agency user cannot create campaigns', function () { ... });
test('campaign creation fails if budget is below minimum', function () { ... });
```

### Frontend (Vitest)

- Co-located with the file under test: `CampaignCard.vue` and `CampaignCard.spec.ts` in the same folder.
- Use `describe` blocks for grouping, `it` for individual tests.
- Descriptions read like behavior:

```ts
describe('CampaignCard', () => {
  it('renders the campaign name', () => { ... })
  it('shows overdue indicator for past end dates', () => { ... })
  it('emits select event when clicked', () => { ... })
})
```

---

## 11. Test data hygiene

### 11.1 Factories produce minimum data

Factories should not over-populate. Default state is the minimum needed for the model to be valid. Tests opt into more state via traits.

### 11.2 Tests must not depend on each other

Each test runs in isolation with a fresh database state (`RefreshDatabase`). One test does not depend on the side effects of another.

### 11.3 Tests must not depend on time

Use `Carbon::setTestNow(...)` or `freezeTime()` for tests that involve timestamps. Never rely on real `now()` because tests become flaky.

### 11.4 Tests must not depend on randomness

Seed random number generators. Use deterministic factories. Never let a test pass-or-fail randomly.

### 11.5 Tests must not depend on environment

Mock external services. Tests must be runnable on any developer's machine, on CI, and in any order.

---

## 12. Anti-patterns to avoid

- **Testing implementation details.** If you have to mock 5 things to test a method, the method's design is wrong, not the test.
- **Snapshot tests for everything.** Snapshots have their place but become noise quickly. Use sparingly.
- **Tests that always pass.** A test that doesn't fail when the code is wrong is worse than no test (gives false confidence).
- **Tautological tests.** `expect($x)->toBe($x)` style. Don't.
- **Tests with massive setup.** If a test needs 50 lines of setup, the system is too coupled. Refactor the system, not the test.
- **Skipping tests with `skip()`.** Either fix it or delete it. Skipped tests become permanent technical debt.
- **Brittle E2E selectors.** Use `data-test` attributes, never CSS classes or text content as test selectors.

---

## 13. Test organization

### Backend

```
tests/
├── Feature/
│   ├── Modules/
│   │   ├── Identity/
│   │   ├── Campaigns/
│   │   └── ...
│   ├── Webhooks/
│   └── Admin/
├── Unit/
│   ├── Modules/
│   │   ├── Campaigns/
│   │   │   ├── Services/
│   │   │   └── ValueObjects/
│   │   └── ...
│   └── Core/
├── Pest.php
└── TestCase.php
```

### Frontend

Tests are **co-located** with the files they test:

```
src/modules/campaigns/
├── components/
│   ├── CampaignCard.vue
│   └── CampaignCard.spec.ts
├── composables/
│   ├── useCampaigns.ts
│   └── useCampaigns.spec.ts
└── ...

tests/
└── e2e/
    ├── creator-onboarding.spec.ts
    ├── campaign-creation.spec.ts
    └── ...
```

E2E tests are separate (`tests/e2e/`) because they cross module boundaries.

---

## 14. Definition of done — testing portion

For every feature:

- ✅ Unit tests for new services / value objects / composables
- ✅ Feature tests for new endpoints (success, validation, auth, authorization)
- ✅ Component tests for new reusable components
- ✅ E2E test added or updated if a critical path is affected
- ✅ Policy tests if authorization changes
- ✅ Audit tests if privileged actions change
- ✅ Migration tests if schema changes
- ✅ Cross-tenant tests if tenant-scoped data is touched
- ✅ Coverage doesn't drop below threshold
- ✅ All tests pass locally before PR
- ✅ All tests pass in CI

---

## 15. Phase-by-phase testing plan

| Phase | Focus                                                                                                              |
| ----- | ------------------------------------------------------------------------------------------------------------------ |
| P1    | Establish all test infrastructure. Hit 80% coverage. All 20 critical paths in Playwright.                          |
| P2    | Add visual regression. Add load testing for top endpoints. Refine performance budgets.                             |
| P3    | Add chaos testing for resilience (random failure injection in staging). Bug bounty introduces external testing.    |
| P4    | Continuous performance regression detection. Production-like full staging environment with realistic data volumes. |

---

**End of testing strategy. No code ships untested.**
