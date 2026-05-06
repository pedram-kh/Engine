# Catalyst Engine

Enterprise-grade two-sided platform for influencer marketing.

This is a monorepo containing the Laravel 11 API, two Vue 3 SPAs (main platform + admin), and shared TypeScript packages (design tokens, UI library, API client).

## Documentation

All architecture, conventions, and phase specifications live in [`docs/`](docs/). Start with:

- [`docs/CURSOR-INSTRUCTIONS.md`](docs/CURSOR-INSTRUCTIONS.md) — how to work on this codebase
- [`docs/ACTIVE-PHASE.md`](docs/ACTIVE-PHASE.md) — current build phase
- [`docs/00-MASTER-ARCHITECTURE.md`](docs/00-MASTER-ARCHITECTURE.md) — system design
- [`docs/02-CONVENTIONS.md`](docs/02-CONVENTIONS.md) — engineering standards (binding)

## Repo layout

```
apps/
  api/          Laravel 11 backend
  main/         Vue 3 SPA — main platform
  admin/        Vue 3 SPA — admin panel
packages/
  ui/           Shared Vue component library
  design-tokens/  Shared design tokens (TS + CSS)
  api-client/   TypeScript SDK for the API
infra/
  terraform/    AWS infrastructure
  sentry/       Sentry project layout
  aws-secrets-manager/  Secrets Manager structure
docs/           All architecture and spec documents
scripts/        Dev tooling
```

## Quickstart

Prerequisites:

- Node 22+ (use [`nvm`](https://github.com/nvm-sh/nvm) — repo `.nvmrc` pins the version)
- pnpm 10+ (`npm install -g pnpm`)
- PHP 8.3+ with `pdo_pgsql`, `redis`, `intl`, `mbstring`, `gd` extensions
- Composer 2.7+
- Docker + Docker Compose

First-time setup:

```bash
./scripts/setup.sh
```

Daily dev:

```bash
docker compose up -d        # Postgres, Redis, Mailhog, MinIO
pnpm dev                    # api on :8000, main on :5173, admin on :5174
```

### Local services

Started by `docker compose up -d`. All ports bind to `127.0.0.1` only.

| Service  | Ports                             | URL / credentials                                      |
| -------- | --------------------------------- | ------------------------------------------------------ |
| Postgres | `5432`                            | `postgres://catalyst:catalyst@localhost:5432/catalyst` |
| Redis    | `6379`                            | `redis://localhost:6379`                               |
| Mailhog  | `1025` (SMTP), `8025` (UI)        | http://localhost:8025                                  |
| MinIO    | `9000` (S3 API), `9001` (console) | http://localhost:9001 — `minioadmin` / `minioadmin`    |

The `minio-init` one-shot container creates the four buckets the platform expects (`catalyst-engine-media`, `catalyst-engine-contracts`, `catalyst-engine-exports`, `catalyst-engine-public`) and exits. Re-run it any time with `docker compose up minio-init`.

Run all tests:

```bash
pnpm test
```

Lint + typecheck:

```bash
pnpm lint
pnpm typecheck
```

## Sprint 0 status

This is **Sprint 0 — Foundation** scaffolding. See [`docs/20-PHASE-1-SPEC.md`](docs/20-PHASE-1-SPEC.md) §5 Sprint 0 for the build target.

Manual cloud-provisioning steps (AWS, Sentry, Stripe Connect, Meta/TikTok/YouTube apps) are documented in [`docs/SPRINT-0-MANUAL-STEPS.md`](docs/SPRINT-0-MANUAL-STEPS.md).

Vendor-dependent features ship behind Laravel Pennant feature flags listed in [`docs/feature-flags.md`](docs/feature-flags.md).
