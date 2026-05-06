# infra/aws-secrets-manager

AWS Secrets Manager layout for Catalyst Engine. Secrets are created manually during Sprint 0 Batches 1 and 2 — see [`docs/SPRINT-0-MANUAL-STEPS.md`](../../docs/SPRINT-0-MANUAL-STEPS.md). Once stable, secret rotation policies are codified in [`infra/terraform/`](../terraform/) (Sprint 16+).

## Path conventions

Every secret name follows:

```
catalyst/${environment}/${component}/${service-or-vendor}
```

- `${environment}` — `staging` or `production`.
- `${component}` — the surface that consumes it: `api`, `spa-main`, `spa-admin`, `worker`, `infra`.
- `${service-or-vendor}` — the integration name (`stripe`, `oauth/meta`, `oauth/tiktok`, …).

Local development never reads from Secrets Manager. Dev `.env` files use mock or empty values.

## Phase 1 secrets

### Vendor / OAuth credentials

| Secret path                        | Contents (JSON keys)                                                   |
| ---------------------------------- | ---------------------------------------------------------------------- |
| `catalyst/${env}/api/stripe`       | `secret_key`, `connect_client_id`, `webhook_secret`, `publishable_key` |
| `catalyst/${env}/api/oauth/meta`   | `app_id`, `app_secret`                                                 |
| `catalyst/${env}/api/oauth/tiktok` | `client_key`, `client_secret`                                          |
| `catalyst/${env}/api/oauth/google` | `client_id`, `client_secret` (YouTube Data API v3 surface)             |
| `catalyst/${env}/api/kyc`          | `provider` (vendor slug), `api_key`, `webhook_secret`                  |
| `catalyst/${env}/api/esign`        | `provider` (vendor slug), `api_key`, `account_id`                      |
| `catalyst/${env}/api/email`        | `provider` (`ses`/`postmark`/`mailgun`), provider-specific keys        |

### Observability

| Secret path                        | Contents |
| ---------------------------------- | -------- |
| `catalyst/${env}/api/sentry`       | `dsn`    |
| `catalyst/${env}/spa-main/sentry`  | `dsn`    |
| `catalyst/${env}/spa-admin/sentry` | `dsn`    |

### Infrastructure runtime

| Secret path                    | Contents                                |
| ------------------------------ | --------------------------------------- |
| `catalyst/${env}/api/laravel`  | `app_key`                               |
| `catalyst/${env}/api/postgres` | `username`, `password` (rotated by RDS) |
| `catalyst/${env}/api/redis`    | `auth_token`                            |

## Reading secrets

### From Laravel (api / worker)

ECS task definitions inject secrets as environment variables via the task's `secrets` array, pointing at the Secrets Manager ARN. Laravel reads them via `config()` only — never `env()` outside `config/`.

### From SPAs (main / admin)

SPAs do not read from Secrets Manager directly. Build-time values (`VITE_SENTRY_DSN_*`) are injected into the GitHub Actions runner from Secrets Manager and passed to `vite build` as env vars. These values are baked into the static bundle.

## Rotation

- Postgres credentials: rotated automatically by RDS (30-day cycle).
- Stripe / OAuth / KYC / e-sign keys: rotated manually on a 90-day cadence (on-call playbook in Phase 2).
- Sentry DSNs: not rotated; revoked and replaced on incident.

## Auditing

CloudTrail captures `GetSecretValue` for every secret. The production account ships CloudTrail events to a security-team-owned S3 bucket (out-of-scope for this repo).
