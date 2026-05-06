# 02 — Conventions

> **Status: Always active reference. Defines the engineering conventions for Catalyst Engine. Cursor must follow these without exception, on every file, in every PR.**

This document defines _how_ code is written, organized, named, tested, and shipped. It is binding. When Cursor produces code that violates these conventions, the developer rejects it.

---

## 1. Repo structure

```
/
├── apps/
│   ├── api/                          # Laravel 11 backend
│   │   ├── app/
│   │   │   ├── Modules/              # Domain modules (see § 3)
│   │   │   │   ├── Identity/
│   │   │   │   ├── Agencies/
│   │   │   │   ├── Brands/
│   │   │   │   ├── Campaigns/
│   │   │   │   ├── Creators/
│   │   │   │   ├── Contracts/
│   │   │   │   ├── Payments/
│   │   │   │   ├── Messaging/
│   │   │   │   ├── Boards/
│   │   │   │   ├── Audit/
│   │   │   │   └── Admin/
│   │   │   ├── Core/                 # Cross-cutting infrastructure
│   │   │   │   ├── Tenancy/
│   │   │   │   ├── Pagination/
│   │   │   │   ├── Errors/
│   │   │   │   ├── Storage/
│   │   │   │   └── Mail/
│   │   │   ├── Http/                 # Thin layer; most logic in modules
│   │   │   │   ├── Middleware/
│   │   │   │   └── Kernel.php
│   │   │   ├── Console/
│   │   │   ├── Exceptions/
│   │   │   └── Providers/
│   │   ├── bootstrap/
│   │   ├── config/
│   │   ├── database/
│   │   │   ├── migrations/
│   │   │   ├── factories/
│   │   │   └── seeders/
│   │   ├── lang/                     # i18n translation files (en, pt, it)
│   │   ├── routes/
│   │   │   ├── api.php               # Mounts module routes
│   │   │   ├── web.php               # Health checks, redirects
│   │   │   └── channels.php          # Broadcasting (Phase 2+)
│   │   ├── tests/
│   │   │   ├── Feature/              # Mirrors Modules structure
│   │   │   ├── Unit/                 # Mirrors Modules structure
│   │   │   └── Pest.php
│   │   ├── composer.json
│   │   ├── phpunit.xml
│   │   ├── phpstan.neon
│   │   └── pint.json
│   │
│   ├── main/                         # Vue 3 SPA — main platform
│   │   ├── public/
│   │   │   └── fonts/                # Self-hosted Inter, JetBrains Mono
│   │   ├── src/
│   │   │   ├── modules/              # Feature modules (see § 4)
│   │   │   │   ├── auth/
│   │   │   │   ├── onboarding/
│   │   │   │   ├── workspace/
│   │   │   │   ├── brands/
│   │   │   │   ├── campaigns/
│   │   │   │   ├── creators/
│   │   │   │   ├── messaging/
│   │   │   │   ├── boards/
│   │   │   │   └── settings/
│   │   │   ├── core/                 # App-wide infrastructure
│   │   │   │   ├── api/              # API client wiring
│   │   │   │   ├── auth/
│   │   │   │   ├── i18n/
│   │   │   │   ├── router/
│   │   │   │   └── stores/
│   │   │   ├── plugins/
│   │   │   │   └── vuetify.ts
│   │   │   ├── App.vue
│   │   │   └── main.ts
│   │   ├── tests/                    # Vitest + Playwright
│   │   ├── package.json
│   │   ├── tsconfig.json
│   │   ├── vite.config.ts
│   │   └── eslint.config.ts
│   │
│   └── admin/                        # Vue 3 SPA — admin panel
│       └── (mirrors apps/main structure)
│
├── packages/
│   ├── ui/                           # Shared Vue component library
│   │   ├── components/
│   │   ├── composables/
│   │   ├── tokens/
│   │   ├── package.json
│   │   └── tsconfig.json
│   ├── design-tokens/                # Shared design tokens (TS + CSS)
│   │   ├── src/
│   │   │   ├── colors.ts
│   │   │   ├── spacing.ts
│   │   │   ├── typography.ts
│   │   │   └── index.ts
│   │   └── package.json
│   └── api-client/                   # TypeScript SDK (auto-generated where possible)
│       ├── src/
│       └── package.json
│
├── infra/
│   └── terraform/
│       ├── modules/
│       ├── environments/
│       │   ├── staging/
│       │   └── production/
│       └── README.md
│
├── docs/                             # All architecture and spec documents
├── scripts/                          # Dev tooling (db reset, seed, etc.)
├── .github/
│   └── workflows/                    # CI/CD
├── package.json                      # Root workspace
├── pnpm-workspace.yaml               # Or turbo.json
├── README.md
└── .gitignore
```

### Workspace tooling

- **Package manager:** `pnpm` with workspaces. Faster, stricter, less disk usage than npm/yarn.
- **Monorepo orchestrator:** none in Phase 1 (pnpm workspaces are enough). Add Turborepo if build orchestration becomes painful.
- **Root scripts** (run from repo root):
  - `pnpm dev` — runs `api`, `main`, `admin` in parallel
  - `pnpm test` — runs all tests in all packages
  - `pnpm lint` — lint everything
  - `pnpm typecheck` — typecheck everything
  - `pnpm build` — build production artifacts

---

## 2. Backend (Laravel) conventions

### 2.1 Modular monolith structure

Catalyst Engine's backend is a **modular monolith**. The Laravel app is one deployable, but code is organized into **modules** under `app/Modules/`. Each module owns its slice of the domain.

**Rules:**

- Modules should be **autonomous**: a module's controllers, services, models, events, jobs, policies, and tests live within the module.
- Modules **communicate via events and explicit service contracts**, not by reaching into each other's internals.
- Modules can depend on `app/Core/` (cross-cutting infrastructure) but should avoid depending on each other directly. When two modules need to interact, prefer events.
- A module's public API is exposed via:
  - HTTP routes (registered in the module's `Routes/api.php`)
  - Service contracts (interfaces in the module's `Contracts/` folder)
  - Events (in the module's `Events/` folder)
- A module's private internals (models, internal services) **must not be referenced from other modules**.

### 2.2 Module folder structure

```
app/Modules/Campaigns/
├── Contracts/                  # Interfaces other modules may use
│   └── CampaignServiceContract.php
├── Controllers/
│   ├── CampaignController.php
│   └── CampaignAssignmentController.php
├── Database/
│   ├── Migrations/             # Module-specific migrations (alternative: keep in /database/migrations)
│   └── Factories/
├── Events/
│   ├── CampaignCreated.php
│   ├── CampaignAssignmentInvited.php
│   └── CampaignAssignmentAccepted.php
├── Listeners/
│   ├── SendInvitationEmail.php
│   └── CreateBoardOnCampaignCreated.php
├── Jobs/
│   └── SyncCampaignMetricsJob.php
├── Models/
│   ├── Campaign.php
│   └── CampaignAssignment.php
├── Policies/
│   ├── CampaignPolicy.php
│   └── CampaignAssignmentPolicy.php
├── Requests/                   # Form Request validation classes
│   ├── CreateCampaignRequest.php
│   └── UpdateCampaignRequest.php
├── Resources/                  # API resource transformers
│   ├── CampaignResource.php
│   └── CampaignAssignmentResource.php
├── Routes/
│   └── api.php
├── Services/
│   ├── CampaignService.php
│   ├── CampaignAssignmentService.php
│   └── CampaignStateMachine.php
├── ValueObjects/
│   └── CampaignBudget.php
└── CampaignsServiceProvider.php
```

The `CampaignsServiceProvider`:

- Registers the module's routes
- Binds the module's contracts to implementations
- Registers event listeners
- Registers policies (or relies on auto-discovery)

Each module's service provider is registered in `bootstrap/providers.php`.

### 2.3 Layering rules

```
Controller
   ↓
Form Request (validation only)
   ↓
Service / Use case (business logic)
   ↓
Model / Repository (persistence)
```

- **Controllers are thin.** They authenticate, validate (via Form Request), call a service, and return a Resource. Maximum ~10 lines per method.
- **Form Requests** handle input validation only. They do not do business logic.
- **Services** do business logic. They are plain PHP classes, dependency-injected. They orchestrate models, dispatch events, send notifications.
- **Models** are Eloquent models. They define relationships, scopes, casts, and accessors/mutators. They do not contain heavy business logic.
- **Repositories** are introduced only when a model has complex query logic that doesn't fit on the model itself. They are not used by default.

### 2.4 Naming conventions

| Type             | Convention                                                                                 | Example                                                        |
| ---------------- | ------------------------------------------------------------------------------------------ | -------------------------------------------------------------- |
| Controllers      | `Verb` + `Resource` + `Controller` for single-action; `ResourceController` for resourceful | `CreateCampaignController`, `CampaignController`               |
| Models           | Singular noun, `PascalCase`                                                                | `Campaign`, `CampaignAssignment`                               |
| Services         | `Resource` + `Service` or domain-named                                                     | `CampaignService`, `PaymentEscrowService`                      |
| Events           | Past tense                                                                                 | `CampaignCreated`, `DraftSubmitted`                            |
| Listeners        | Verb phrase describing the response                                                        | `SendInvitationEmail`                                          |
| Jobs             | Verb phrase + `Job`                                                                        | `SyncCreatorMetricsJob`                                        |
| Policies         | Model name + `Policy`                                                                      | `CampaignPolicy`                                               |
| Form Requests    | Verb + Model + `Request`                                                                   | `CreateCampaignRequest`                                        |
| Resources        | Model + `Resource`                                                                         | `CampaignResource`                                             |
| Migrations       | `verb_noun_table_or_action`                                                                | `create_campaigns_table`, `add_status_to_campaign_assignments` |
| Database tables  | Plural snake_case                                                                          | `campaigns`, `campaign_assignments`                            |
| Database columns | Singular snake_case                                                                        | `agency_id`, `created_at`                                      |
| Routes (API)     | Plural kebab-case resource names                                                           | `/api/v1/agencies/{agency}/campaigns`                          |
| Translation keys | `module.section.key`                                                                       | `campaigns.errors.budget_invalid`                              |

### 2.5 Type safety

- **Every method has a return type.** No exceptions.
- **Every parameter has a type declaration.** No exceptions.
- **`array` is the type of last resort.** Prefer DTOs, value objects, or specific collection types.
- **Larastan level 8** must pass on every commit.
- **No use of `mixed`** unless genuinely unavoidable, and then with a `// @phpstan-ignore-next-line` comment explaining why.
- **Strict types declared** at the top of every PHP file: `declare(strict_types=1);`.

### 2.6 Eloquent conventions

- **Models declare their fillable fields explicitly.** `$guarded = []` is forbidden.
- **Casts are declared for every non-primitive column.** Dates, booleans, JSON, enums, ULIDs.
- **Relationships are typed.** Return type on every relationship method.
- **No model events in `boot()`** for production logic — use observers in `Observers/` folder, registered in the service provider.
- **Global scopes for tenancy** are applied via the `BelongsToAgency` trait. Models that are tenant-scoped use this trait.
- **Soft deletes** are the default for: `Creator`, `Agency`, `Brand`, `Campaign`, `CampaignAssignment`, `Contract`, `Payment`, `User`. Other models hard-delete.
- **Public-facing IDs are ULIDs**, not auto-increment integers. Internal `id` column is bigint for performance; `ulid` column is the public identifier exposed in API responses.

Example model:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Models;

use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Campaign extends Model
{
    use BelongsToAgency;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'brand_id',
        'name',
        'description',
        'objective',
        'status',
        'budget_minor_units',
        'budget_currency',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'objective' => CampaignObjective::class,
            'status' => CampaignStatus::class,
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CampaignAssignment::class);
    }

    protected static function newFactory(): CampaignFactory
    {
        return CampaignFactory::new();
    }
}
```

### 2.7 Enums

Use **PHP backed enums** for any fixed set of values. Do not use string constants.

```php
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
```

Stored as the string in the database. Cast in the model.

### 2.8 Value objects

Use value objects for domain concepts that have invariants or behavior beyond a primitive. Examples: `Money`, `CampaignBudget`, `DateRange`, `EngagementRate`. Place them in the module's `ValueObjects/` folder. Make them `final readonly` classes.

### 2.9 Service layer

A service class:

- Is a `final` class
- Receives its dependencies via constructor injection
- Has methods that represent **use cases** (`createCampaign`, `inviteCreator`, `releasePayment`)
- Returns models, value objects, or DTOs — not arrays or raw query results
- Throws domain exceptions (`CampaignBudgetExceededException`) — not generic exceptions
- Dispatches events for cross-cutting concerns (audit, notifications, downstream side effects)

Services are bound in the module's service provider when an interface contract exists; otherwise auto-resolved.

### 2.10 Dispatching events

Side effects (notifications, audit, downstream module reactions) are triggered via events, not via direct calls between modules.

```php
// In CampaignAssignmentService::accept()
event(new CampaignAssignmentAccepted($assignment, $acceptedBy));
```

The Boards module listens for `CampaignAssignmentAccepted` and moves the relevant card. The Audit module listens to log the event. Neither knows about the other.

### 2.11 Authorization

- Every controller method that touches a resource calls `$this->authorize($action, $resource)`.
- Every policy has full test coverage including: owner can do X, non-owner cannot do X, cross-tenant cannot do X.
- Authorization happens at the controller layer, before reaching the service. Services trust their inputs.

### 2.12 Audit logging

- Every state-changing operation that affects an externally meaningful entity emits an audit event.
- Audit is implemented via the `Audited` trait + an `AuditObserver` that listens to model events and via explicit `Audit::log(...)` calls in services for actions that aren't simple CRUD.
- The audit table is append-only. Never updated, never (manually) deleted.

### 2.13 Database migrations

- Every migration is a separate file.
- Migrations use `up()` and `down()`. Both are tested (`down()` actually reverses cleanly).
- Schema changes after Phase 1 ships follow expand/migrate/contract — see `08-DATABASE-EVOLUTION.md`.
- Naming: `2026_05_01_120000_create_campaigns_table.php`, `2026_06_01_120000_add_objective_to_campaigns_table.php`.
- Column ordering in `up()`: id → ULIDs → foreign keys → required → optional → soft delete → timestamps.
- Indexes are added in the same migration as the column they support.
- Foreign keys use `ON DELETE` explicitly (`cascadeOnDelete()`, `restrictOnDelete()`, `nullOnDelete()`). Never default behavior.

### 2.14 Form Requests

- Every API endpoint that accepts input uses a Form Request.
- Validation rules are exhaustive — every field, every constraint.
- Custom validation rules go in `app/Core/Validation/Rules/`.
- Translation keys are used for messages: `__('campaigns.validation.budget_required')`.

### 2.15 API Resources

- Every API response that returns model data uses an API Resource (`JsonResource`).
- Resources do not leak internal IDs — they expose ULIDs.
- Resources are versioned alongside the API (Phase 1 routes are `/api/v1/...`; Resources live in `Resources/V1/` if breaking changes are made later).
- Resources include `links` for related entities and pagination.
- See `04-API-DESIGN.md` for the standard envelope shape.

### 2.16 Background jobs

- Long-running, retryable, or non-blocking work goes through queued jobs.
- Jobs are idempotent where possible.
- Jobs declare their queue: `public string $queue = 'social-sync';`.
- Jobs declare retry policy: `public int $tries = 3;` and `public int $backoff = 60;`.
- Failed jobs go to a dead-letter queue and surface in the admin SPA.

### 2.17 Mail

- Every transactional email is a Mailable class.
- Templates use Blade with markdown.
- Locale is set per-recipient (`->locale($creator->preferred_language)`).
- All copy is in i18n files.

### 2.18 Static analysis & linting

- **Larastan** at level 8 in CI. PR fails if level drops.
- **Laravel Pint** with the Laravel preset; run on every commit (pre-commit hook).
- **Rector** (optional, recommended) for ongoing PHP modernization.

---

## 3. Frontend (Vue 3) conventions

### 3.1 Module structure (per SPA)

Each Vue SPA organizes by **feature module** under `src/modules/`. A module corresponds to a domain area (campaigns, creators, brands).

```
src/modules/campaigns/
├── components/                # Vue components specific to this module
│   ├── CampaignList.vue
│   ├── CampaignDetail.vue
│   └── CampaignForm.vue
├── composables/               # Module-specific composables
│   ├── useCampaigns.ts
│   └── useCampaignForm.ts
├── stores/                    # Pinia stores
│   └── useCampaignsStore.ts
├── api/                       # Module-specific API client functions
│   └── campaigns.api.ts
├── types/                     # Module-specific TypeScript types
│   └── campaign.types.ts
├── routes.ts                  # Module's route definitions
└── index.ts                   # Module's public API
```

### 3.2 Vue component conventions

- **`<script setup lang="ts">` only.** No Options API.
- **One component per file.** No multi-component files.
- **Filename = component name = PascalCase.** `CampaignList.vue`, not `campaign-list.vue`.
- **Props are typed** via `defineProps<Props>()` with an explicit interface.
- **Events are typed** via `defineEmits<{...}>()`.
- **Slots are typed** via `defineSlots<{...}>()`.
- **Components are kept small** — under 200 lines preferred. Split when larger.
- **Logic-heavy components extract to composables.** Components handle templates and bindings; composables handle logic.

Example skeleton:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { Campaign } from '@/modules/campaigns/types/campaign.types'
import CButton from '@/packages/ui/CButton/CButton.vue'

interface Props {
  campaign: Campaign
  selectable?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  selectable: false,
})

const emit = defineEmits<{
  select: [campaign: Campaign]
  archive: [campaign: Campaign]
}>()

const isOverdue = computed(() => {
  if (!props.campaign.endsAt) return false
  return new Date(props.campaign.endsAt) < new Date()
})
</script>

<template>
  <div class="campaign-card" :class="{ 'is-overdue': isOverdue }">
    <h3>{{ campaign.name }}</h3>
    <CButton @click="emit('select', campaign)">
      {{ $t('campaigns.actions.select') }}
    </CButton>
  </div>
</template>

<style scoped>
.campaign-card {
  padding: var(--space-4);
  border-radius: 8px;
  border: 1px solid var(--color-border-subtle);
}
.campaign-card.is-overdue {
  border-color: var(--color-action-danger);
}
</style>
```

### 3.3 Composables

- Named with the `use` prefix: `useCampaigns`, `useDebounce`, `useToast`.
- Each composable does **one thing**.
- Returns reactive references and methods.
- Has a single test file alongside.
- Composables that fetch data return `{ data, error, loading, refetch }` consistently.

### 3.4 Pinia stores

- One store per domain area.
- Stores hold **shared state** that multiple components need. Component-local state stays in `ref()`.
- Use the **setup syntax** for stores (not the options syntax).
- Store actions return promises and throw on error — let components handle the error UX.

### 3.5 API client layer

- All HTTP calls go through `packages/api-client/`.
- The API client is a thin typed wrapper around `axios` (or `ofetch`).
- Endpoints are functions: `getCampaigns(agencyId, params)`, `createCampaign(agencyId, data)`.
- Types are auto-generated from the backend's OpenAPI spec where possible (Phase 2; manual in Phase 1).
- **Components and composables never call `axios` directly.** They go through `api-client`.

### 3.6 Routing

- Each module exports its own routes from `module/routes.ts`.
- The root router composes module routes.
- Lazy load heavy modules: `component: () => import('@/modules/campaigns/...')`.
- Route guards for auth and authorization in `core/router/guards.ts`.

### 3.7 i18n

- All user-facing strings go through `$t()` or `useI18n().t()`.
- Translation files live in `src/core/i18n/locales/{en,pt,it}/{module}.json`.
- Pluralization uses ICU MessageFormat (vue-i18n supports this).
- Date and number formatting via `Intl.DateTimeFormat` and `Intl.NumberFormat`, locale-aware.
- Currency formatting via a shared utility, never raw `toFixed(2)`.

### 3.8 Styling

- **Scoped styles in components.** Global styles only in `core/styles/global.css`.
- **CSS variables for tokens.** Never hardcode colors, spacings, font sizes.
- **No CSS frameworks** other than Vuetify. No Tailwind.
- **No CSS-in-JS libraries.** Native `<style scoped>` is enough.

### 3.9 Naming conventions (frontend)

| Type            | Convention                          | Example                                 |
| --------------- | ----------------------------------- | --------------------------------------- |
| Components      | PascalCase, descriptive             | `CampaignList.vue`                      |
| Composables     | camelCase with `use` prefix         | `useCampaigns.ts`                       |
| Stores          | camelCase with `use*Store`          | `useCampaignsStore.ts`                  |
| Types           | PascalCase                          | `Campaign`, `CampaignStatus`            |
| API functions   | camelCase, verb-first               | `getCampaigns`, `createCampaign`        |
| Pinia state     | camelCase                           | `currentCampaign`, `isLoading`          |
| Pinia actions   | camelCase, verb-first               | `loadCampaigns`, `createCampaign`       |
| Events          | camelCase, past tense or imperative | `update`, `select`, `submit`            |
| CSS classes     | kebab-case                          | `.campaign-card`, `.is-overdue`         |
| Files (non-Vue) | kebab-case                          | `campaign.types.ts`, `use-campaigns.ts` |

### 3.10 Type safety (frontend)

- TypeScript strict mode enabled.
- `noImplicitAny`, `strictNullChecks`, `strictFunctionTypes`, `noImplicitReturns` all on.
- No `any` without an inline comment justifying it.
- Prefer `unknown` over `any` when the type is genuinely unknown.
- Use `import type` for type-only imports.
- Explicit return types on functions exposed by modules. Composables and store actions especially.

### 3.11 Linting & formatting

- **ESLint** with Vue 3 + TypeScript plugins. Strict config.
- **Prettier** for formatting. Pre-commit hook runs Prettier.
- **No console.log in committed code.** Use a logger utility or remove.
- **No commented-out code in commits.** Delete or replace.

---

## 4. Testing conventions

Full strategy in `07-TESTING.md`. Highlights here:

### 4.1 Backend (Pest)

- **Feature tests** for every API endpoint covering success, validation errors, auth errors, authorization errors, edge cases.
- **Unit tests** for every service class.
- **Policy tests** for every policy.
- **Tenancy tests** for every tenant-scoped model — verify cross-tenant access fails.
- **Audit tests** confirming privileged actions emit audit entries.

Test file structure mirrors source: `tests/Feature/Modules/Campaigns/CreateCampaignTest.php`.

### 4.2 Frontend (Vitest + Playwright)

- **Component tests** for every reusable component.
- **Composable tests** for every composable.
- **Store tests** for every Pinia store.
- **E2E tests** (Playwright) for critical user journeys (full list in Phase 1 spec).

### 4.3 Coverage thresholds

- Backend: 80% line coverage minimum, enforced in CI.
- Frontend: 80% line coverage minimum, enforced in CI.
- 100% coverage required for: auth, payments, audit, authorization. PR fails if these drop.

---

## 5. Git workflow

### 5.1 Branching

- Trunk-based with short-lived feature branches.
- `main` is always deployable.
- Feature branches: `feat/<short-description>`. Bug fixes: `fix/<short-description>`. Chores: `chore/...`.
- Merge to `main` via PR. Squash merge by default. Linear history.

### 5.2 Commits

**Conventional Commits** format. Required for all commits.

```
feat(campaigns): add bulk creator invitation
fix(payments): handle Stripe webhook idempotency
chore(deps): bump axios to 1.7
docs(architecture): clarify tenancy model
test(brands): add policy coverage for cross-tenant access
refactor(boards): extract column move logic to service
```

Types: `feat`, `fix`, `chore`, `docs`, `test`, `refactor`, `perf`, `style`, `build`, `ci`.

Scope is the module name when applicable.

### 5.3 Pull requests

Every PR:

- Has a descriptive title (Conventional Commits format).
- Has a description explaining **what** and **why** (not how — code shows that).
- Links to the relevant phase spec section if applicable.
- Has a "Testing" section describing how the change was verified.
- Has a "Checklist" with all `definition of done` items checked.
- CI passes.
- All checks pass (lint, typecheck, tests, coverage, security scan).
- For solo workflow: developer self-reviews before merge. PR description still required (it's documentation).

### 5.4 Hooks

- **Pre-commit:** lint + format on staged files (Husky + lint-staged).
- **Pre-push:** run unit tests for changed modules.
- **Commit-msg:** validate Conventional Commits format (commitlint).

---

## 6. Environment configuration

### 6.1 Environments

- `local` — developer machine
- `staging` — pre-production, mirrors prod
- `production`

### 6.2 Configuration

- **No `.env` files in repo.** `.env.example` is committed; `.env` is local-only and gitignored.
- **Production secrets in AWS Secrets Manager.** Loaded into Laravel's env at boot via a custom loader.
- **Frontend build-time config** via `VITE_*` env vars, baked into the bundle at build.
- **Frontend runtime config** (rare, but useful for feature flags) via a `/api/v1/config/public` endpoint that returns runtime-toggleable values.

### 6.3 Local dev setup

- **Docker Compose** for local services: Postgres, Redis, Mailhog.
- **Makefile or `pnpm` scripts** for common tasks: `pnpm db:reset`, `pnpm db:seed`, `pnpm test`.
- **Laravel Sail** is acceptable but the project uses a custom docker-compose.yml because we need Vue SPAs alongside.

---

## 7. Documentation conventions

### 7.1 Code comments

- **Why, not what.** Comments explain reasons, edge cases, and non-obvious decisions.
- **No comment needed when the code is clear.** Don't restate the obvious.
- **TODO/FIXME comments include a context line.** `// TODO(pk): handle multi-currency conversion when Phase 2 lands`.

### 7.2 API documentation

- OpenAPI 3.1 spec generated from controller annotations (Scribe or similar).
- Spec is committed and viewable at `/api/docs` in non-production environments.
- Major API changes require an updated spec.

### 7.3 Module READMEs

- Each backend module has a `README.md` explaining its purpose, public contracts, and event surface.
- Each frontend module has a `README.md` explaining its routes, key components, and stores.

### 7.4 Decision records

- Significant architectural decisions are recorded as ADRs (Architecture Decision Records) in `docs/adr/`.
- One ADR per decision, dated, with status (proposed / accepted / superseded).
- Examples of decisions worth an ADR: choice of payment provider, choice of search engine when migrating from Postgres FTS, choice of ML hosting platform.

---

## 8. Performance conventions

- **No N+1 queries.** Eager-load relationships when iterating.
- **Database query budget per request:** under 20 queries in development. Telescope alerts on >20.
- **API responses include `Cache-Control` headers** where appropriate (immutable resources, public profile photos).
- **Frontend bundles split per route** via Vite's automatic code-splitting.
- **Images served via CloudFront** with appropriate cache headers.

---

## 9. Security conventions

- **All input validated.** No raw `$request->input()` reaching business logic.
- **All output escaped.** Vue's `{{ }}` escapes by default; never use `v-html` with untrusted content.
- **Mass assignment protection:** explicit `$fillable` on every model.
- **CSRF tokens** on every state-changing request (Sanctum SPA auth handles this).
- **Rate limiting** on every API route group (`throttle:60,1` baseline; tighter on auth endpoints).
- **CORS** configured strictly: only the SPA origins.
- **Content Security Policy** headers in production.
- **Dependency updates:** Dependabot weekly, security patches within 72 hours.

Full security spec in `05-SECURITY-COMPLIANCE.md`.

---

## 10. Definition of done (per feature, per PR)

A feature is shippable only when:

1. ✅ Code matches the spec
2. ✅ All tests pass locally
3. ✅ Coverage doesn't drop
4. ✅ Larastan level 8 passes
5. ✅ TypeScript strict passes
6. ✅ Pint and Prettier produce no diffs
7. ✅ ESLint passes with no warnings
8. ✅ All user-facing strings in i18n files (en, pt, it)
9. ✅ Audit logging in place for privileged actions
10. ✅ Migrations follow expand/migrate/contract if touching live tables
11. ✅ Authorization policy in place and tested
12. ✅ Empty / loading / error states implemented (frontend)
13. ✅ Light + dark mode tested (frontend)
14. ✅ Keyboard navigation works (frontend)
15. ✅ PR description complete with Testing section
16. ✅ Documentation updated if applicable

If any of these are missing, the feature is not done. Cursor does not mark something complete in chat until all items pass.

---

**End of conventions. Code that violates these is rejected.**
