# Cursor Instructions — Catalyst Engine

> **Read this file first, before any other file in `/docs`. Re-read it at the start of every new session.**

You are working on **Catalyst Engine** — an enterprise-grade two-sided platform for influencer marketing, built across four planned phases. This file tells you how to work on this codebase. The other files in `/docs` tell you what to build.

---

## 1. What you must do at the start of every session

1. **Read this file (`CURSOR-INSTRUCTIONS.md`) in full.**
2. **Read `ACTIVE-PHASE.md`** — it tells you which phase is currently being built.
3. **Read the active phase spec** (e.g. `20-PHASE-1-SPEC.md` if Phase 1 is active).
4. **Reference the foundation documents as needed** during your work:
   - `00-MASTER-ARCHITECTURE.md` — system design, scale targets, tech stack
   - `01-UI-UX.md` — design system, brand, components
   - `02-CONVENTIONS.md` — coding standards, naming, repo structure
   - `03-DATA-MODEL.md` — entity schema across all phases
   - `04-API-DESIGN.md` — API conventions
   - `05-SECURITY-COMPLIANCE.md` — security and GDPR
   - `06-INTEGRATIONS.md` — third-party services
   - `07-TESTING.md` — testing requirements
   - `08-DATABASE-EVOLUTION.md` — migration discipline
   - `09-ADMIN-PANEL.md` — admin SPA structure
   - `10-BOARD-AUTOMATION.md` — board engine and event automation

5. **Future phase specs (`21-`, `22-`, `23-`) are REFERENCE ONLY.** They exist so you understand the long-term shape of the system and design seams correctly. **You must not implement features from future phases.**

---

## 2. The phase model

This product is built in four phases:

| Phase | Goal                                                | Status File          |
| ----- | --------------------------------------------------- | -------------------- |
| 1     | Foundation, agency pilot, Catalyst V0 features      | `20-PHASE-1-SPEC.md` |
| 2     | Scale, brand portals, second agency, mobile         | `21-PHASE-2-SPEC.md` |
| 3     | Direct brands, marketplace, AI intelligence         | `22-PHASE-3-SPEC.md` |
| 4     | Category leadership, vertical AI, platform maturity | `23-PHASE-4-SPEC.md` |

`ACTIVE-PHASE.md` contains a single line indicating which phase is active. Only that phase's features may be implemented. The data model and architecture, however, must already accommodate **all four phases**. This is a deliberate trade: you design for the final shape, you build only the active scope.

---

## 3. The "design for final, build for active" rule

This is the most important rule in this project.

### What "design for final" means

- Database tables include columns that future phases need, even if Phase 1 doesn't read or write them yet (default values, nullable, with sensible fallbacks)
- Entity relationships reflect the full model (`Agency → Brand → Campaign → Creator-Assignment`) from day one, even if some entities have only one row
- API endpoints follow the final REST conventions from day one
- Auth, audit, and permissions are designed for the full role matrix (agency admin/manager/staff, brand client, creator, admin) even when some roles aren't used yet
- Multi-tenancy is enforced everywhere from the first migration

### What "build for active" means

- UI screens are only built for active-phase features
- Business logic for future-phase features is not implemented
- Future-phase third-party integrations are not connected
- Future-phase background jobs are not registered

### How to handle ambiguity

If you're unsure whether a feature is in scope:

1. Check the active phase spec's "In Scope" section — if listed, build it
2. Check the active phase spec's "Out of Scope" section — if listed, do not build it
3. If neither, **stop and ask before building**. Do not assume.

---

## 4. Tech stack (binding)

These choices are locked. Do not deviate without an explicit instruction.

### Backend

- **Language:** PHP 8.3+
- **Framework:** Laravel 11+
- **Database:** PostgreSQL 16+ (RDS in production)
- **Cache & Queues:** Redis (ElastiCache in production)
- **Queue worker:** Laravel Horizon
- **Auth (API):** Laravel Sanctum
- **Storage:** Amazon S3
- **Search (Phase 1):** PostgreSQL full-text. Migration to Meilisearch/OpenSearch deferred to Phase 2/3
- **Static analysis:** Larastan (PHPStan for Laravel) at level 8
- **Code style:** Laravel Pint (PSR-12 + Laravel preset)
- **Testing:** Pest

### Frontend (Main App + Admin SPA — same stack)

- **Framework:** Vue 3 (Composition API only — no Options API)
- **Language:** TypeScript (strict mode)
- **UI library:** Vuetify 3
- **State management:** Pinia
- **Routing:** Vue Router
- **Build:** Vite
- **Linting:** ESLint
- **Formatting:** Prettier
- **Testing:** Vitest (unit/component), Playwright (E2E)

### Infrastructure

- **Cloud:** AWS, primary region `eu-central-1` (Frankfurt). DR region `eu-west-1` (Ireland)
- **Hosting:** AWS ECS Fargate behind ALB, or AWS Elastic Beanstalk if simpler. Decision deferred to architecture doc
- **CDN:** CloudFront
- **IaC:** Terraform
- **CI/CD:** GitHub Actions
- **Secrets:** AWS Secrets Manager
- **Observability:** CloudWatch + Sentry (errors) + a product analytics tool TBD

### Two distinct frontends, one backend

The project contains **two separate Vue SPAs** sharing one Laravel API:

- `apps/main/` — the platform (agency, brand, creator users)
- `apps/admin/` — the internal admin SPA (Catalyst Engine ops staff only)
- `apps/api/` — the Laravel backend serving both

The two frontends share a component library and design tokens but have separate routes, auth flows, and bundles.

---

## 5. Repo structure (binding for Phase 1)

```
/
├── apps/
│   ├── api/                 # Laravel 11 backend
│   ├── main/                # Vue 3 SPA — main platform
│   └── admin/               # Vue 3 SPA — admin panel
├── packages/
│   ├── ui/                  # Shared Vue component library
│   ├── design-tokens/       # Shared design tokens (colors, spacing, typography)
│   └── api-client/          # TypeScript SDK for the API (auto-generated where possible)
├── infra/
│   └── terraform/           # IaC for AWS
├── docs/                    # All architecture and spec documents (you are here)
└── scripts/                 # Dev tooling
```

The Laravel backend uses a **modular monolith** structure under `app/Modules/` with each module owning its own routes, models, controllers, services, events, and tests. See `02-CONVENTIONS.md` for module layout.

---

## 6. How you write code

### Type safety is non-negotiable

- Backend: every method has parameter and return type declarations. Larastan level 8 must pass.
- Frontend: TypeScript strict mode. No `any` without an explicit comment justifying it.

### Tests are non-negotiable

- Every new feature ships with tests in the same PR. No exceptions.
- Backend: feature tests for every endpoint, unit tests for every service class.
- Frontend: component tests for every reusable component, store tests for every Pinia store.
- Critical paths (auth, payments, audit, authorization) require 100% coverage.
- See `07-TESTING.md` for the full strategy.

### Audit logging is non-negotiable

- Every privileged action emits an audit event with: actor, action, target, timestamp, reason (if mutative).
- Use the `Audited` trait on relevant models.
- See `05-SECURITY-COMPLIANCE.md` for the full audit specification.

### Migrations are non-negotiable

- Every schema change follows the **expand → migrate → contract** pattern (see `08-DATABASE-EVOLUTION.md`).
- Never drop a column in the same migration that adds its replacement.
- Every `up()` has a working, tested `down()`.
- Long-running migrations are queued jobs, not blocking deploys.

### Multi-tenancy is non-negotiable

- Every query that touches tenant-scoped data must be scoped by tenant.
- Use Laravel global scopes on tenant-aware models.
- Tests must include cross-tenant access attempts and verify they fail.
- See `00-MASTER-ARCHITECTURE.md` for the tenancy model.

---

## 7. How you handle uncertainty

You are working with a solo developer. They cannot watch every line you write. When you are uncertain about anything:

1. **Stop.** Do not guess.
2. **State the uncertainty plainly** in chat: "I'm unsure whether X should Y or Z because [reason]. Which do you want?"
3. **Suggest the option you'd recommend** and why, but wait for confirmation before implementing.
4. **Never silently choose between architecturally significant options.** Examples that require asking:
   - Whether a feature belongs in a new module or an existing one
   - Whether a column belongs on table A or table B
   - Whether an action requires a new permission or fits an existing one
   - Whether a third-party service should be added when one isn't specified

5. **Examples that don't require asking:**
   - Variable names within a function
   - Internal helper methods
   - Test descriptions
   - CSS class names within a component
   - Refactoring within a single file

When in doubt, prefer asking over guessing. The cost of a clarifying question is small. The cost of building the wrong thing is large.

---

## 8. How you handle scope

**Stay inside the active phase.** If you find yourself thinking "while I'm in here, I might as well also build...", stop. Add a note to chat about what you noticed and let the developer decide whether to expand scope.

**Do not implement future-phase features even if it seems easy.** A "small" Phase 3 feature added in Phase 1 means Phase 1 is now testing, supporting, and migrating something not designed for. Future phases plan their work around what they expect to inherit; surprises are not free.

**Do not delete future-phase fields from the data model just because Phase 1 doesn't use them.** They are intentional.

---

## 9. How you handle disagreement with these instructions

If you believe an instruction in this document or any other doc is wrong, say so explicitly. Do not silently work around it. The developer wants you to push back when you have a real reason.

When you push back:

- Quote the specific instruction you disagree with
- Explain the concrete problem you see
- Propose an alternative
- Wait for the developer's decision

---

## 10. Internationalization (Phase 1)

The platform supports **English, Portuguese, and Italian** from Phase 1.

- All user-facing strings live in i18n files. Hardcoded English strings are not acceptable.
- Backend: use Laravel's localization (`__()` and `lang/` files).
- Frontend: use `vue-i18n` with separate JSON files per locale.
- Database content (creator-supplied content) is stored as written; do not auto-translate.
- Currency: GBP and EUR primary in Phase 1. Display logic is locale-aware.
- Dates: locale-aware formatting throughout. Store UTC, display local.

---

## 11. Security defaults

- Never log secrets, tokens, passwords, full credit card numbers, or full government IDs.
- Never put secrets in code. Use AWS Secrets Manager via Laravel's environment config.
- Never expose internal IDs that allow enumeration without authorization checks. Use ULIDs for public-facing identifiers.
- Always use parameterized queries. Never concatenate user input into SQL.
- Always validate file uploads (type, size, content) and store under user-scoped paths.

---

## 12. Definition of "done" for any feature

A feature is done when:

1. ✅ Implementation matches the spec
2. ✅ Backend tests pass (unit + feature)
3. ✅ Frontend tests pass (component + relevant E2E)
4. ✅ Larastan level 8 passes
5. ✅ TypeScript strict passes
6. ✅ Pint and Prettier produce no diffs
7. ✅ All user-facing strings are in i18n files for en, pt, it
8. ✅ Audit logging is in place for privileged actions
9. ✅ Migration follows expand/migrate/contract if it touches existing tables
10. ✅ Documentation updated if the feature changes API or data model

If any of these are missing, the feature is not done. Do not mark it complete in chat.

---

## 13. The single source of truth

The documents in `/docs` are the source of truth. If chat instructions and documentation conflict, ask the developer to resolve the conflict — don't silently follow one over the other. If a document is wrong, the document is updated; only then is the code changed.

---

**End of Cursor instructions. Now read `ACTIVE-PHASE.md` to find out what to build.**
