# infra/sentry

Sentry organization layout for Catalyst Engine. Configured manually in the Sentry dashboard during Sprint 0 Batch 2 — see [`docs/SPRINT-0-MANUAL-STEPS.md`](../../docs/SPRINT-0-MANUAL-STEPS.md#batch-2).

## Organization

- Slug: `catalyst-engine`
- Plan: Team (or higher when production traffic justifies)
- Region: EU (`de` data residency) to align with eu-central-1 deployment and GDPR posture.

## Projects (3)

Three Sentry projects, one per deployable surface. Each project gets its own DSN; DSNs are stored in AWS Secrets Manager and passed to runtime via env vars.

| Project slug     | Platform         | Consumed by                                 | Env var                 | AWS Secrets Manager path           |
| ---------------- | ---------------- | ------------------------------------------- | ----------------------- | ---------------------------------- |
| `catalyst-api`   | `php-laravel`    | `apps/api` (Laravel + queue workers)        | `SENTRY_LARAVEL_DSN`    | `catalyst/${env}/api/sentry`       |
| `catalyst-main`  | `javascript-vue` | `apps/main` SPA (browser + build pipeline)  | `VITE_SENTRY_DSN_MAIN`  | `catalyst/${env}/spa-main/sentry`  |
| `catalyst-admin` | `javascript-vue` | `apps/admin` SPA (browser + build pipeline) | `VITE_SENTRY_DSN_ADMIN` | `catalyst/${env}/spa-admin/sentry` |

`${env}` is one of `staging` or `production`. Local dev does not push events; the env vars stay empty in `.env.example`.

## Environments per project

Each project uses Sentry's environments feature with these labels:

- `local` — never reported (DSNs blank in dev)
- `staging`
- `production`

## Releases

A Sentry "release" is created per deployment, keyed by git short SHA. The CI/CD pipeline (Sprint 16) uploads source maps for the SPAs and a release marker for the API.

## Alerts

Alert routing is configured in the Sentry dashboard, not as code:

- New issues in `production` → Slack `#catalyst-alerts` + on-call PagerDuty rotation.
- Performance regressions on critical transactions → Slack `#catalyst-alerts`.
- Issues in `staging` → Slack `#catalyst-staging` only (lower urgency).

## What lives here

Currently this directory contains only this README. If we later add Sentry-as-code via [Sentry's Terraform provider](https://registry.terraform.io/providers/jianyuan/sentry/latest/docs), it will live in this directory.
