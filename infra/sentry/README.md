# infra/sentry

Sentry organization layout for Catalyst Engine. Rewritten 2026-11-15 (AH-121 C4) to what was built and trial-checked; the Sprint-0 draft described three projects, Secrets Manager paths, Slack and PagerDuty, none of which exist. Full plan and evidence: [`docs/reviews/sentry-plan.md`](../../docs/reviews/sentry-plan.md). Deploy: [`docs/runbooks/deploy-ah121-ah122.md`](../../docs/runbooks/deploy-ah121-ah122.md). Phone: [`docs/runbooks/native-sentry.md`](../../docs/runbooks/native-sentry.md).

**Everything about the Sentry side below is reported by Pedram (2026-11-13) unless it says it was read; the repo cannot see the org.**

## Organization

- Slug: `catalyst-engine`. Data storage location: **EU**.
- Plan: **14-day trial, no paid plan chosen.** What the free plan keeps after the trial (retention, the one uptime monitor): **not read**. Sentry's pricing page states 30 days retention on the free plan; the org setting was **not read**.
- Org settings ON: Require Data Scrubber, Require Using Default Scrubbers, Prevent Storing of IP Addresses. **Not read back:** Enhanced Privacy, Allow Shared Issues, Global Sensitive Fields.
- Organisation Advanced Data Scrubbing rule: Remove / Anything / `$user.geo.**` (set by Pedram, 2026-11-14). Sentry derives geo from the IP even with IP storing off; this rule is the only control found. Re-check geo once per project when its DSN first goes live.

## Projects (4), errors only

Errors only: no logging, tracing, profiling, metrics or session replay on any project. **Four projects, one per surface, the `environment` tag instead of a staging project** (ruling Q6).

| Project slug     | Platform         | Consumed by                                  | Env var                 | Where the DSN lives (who sets it)                                          |
| ---------------- | ---------------- | -------------------------------------------- | ----------------------- | -------------------------------------------------------------------------- |
| `catalyst-api`   | `php-laravel`    | `apps/api` (Laravel, queue workers, erasure) | `SENTRY_LARAVEL_DSN`    | The box's `.env` (the colleague)                                           |
| `catalyst-main`  | `javascript-vue` | `apps/main` on the web                       | `VITE_SENTRY_DSN_MAIN`  | The Netlify site env of the main site (the colleague)                      |
| `catalyst-admin` | `javascript-vue` | `apps/admin`                                 | `VITE_SENTRY_DSN_ADMIN` | The Netlify site env of the admin site (the colleague)                     |
| `catalyst-app`   | `capacitor`      | `apps/main` inside the iOS and Android app   | `VITE_SENTRY_DSN_APP`   | The gitignored `apps/main/.env.native-production` on Pedram's Mac (Pedram) |

**The DSNs are not in AWS Secrets Manager or SSM Parameter Store.** Pedram supplies each value privately, never in chat or the repo. An empty DSN sends nothing on every surface. Native-mode builds read only `_APP`, web builds only `_MAIN` (pinned by a test). Build-time source-map settings (`SENTRY_AUTH_TOKEN`, `SENTRY_ORG`, `SENTRY_PROJECT`, `SENTRY_URL`) go in each Netlify site env, names listed in each SPA's `.env.example`; their values were not read.

## Environments

The `environment` tag: API from `SENTRY_ENVIRONMENT` (empty means `APP_ENV`); SPAs from the Vite mode (`production`, `development`); the phone `production` for the `native-production` build, else `development`. Local development never reports (DSNs empty).

## Releases

Named by **content, not commit SHA**, because the Bitbucket mirror rewrites SHAs: `catalyst-api@<12 hex>` (git tree hash of `apps/api`, written into the box `.env` as `SENTRY_RELEASE` at deploy), `catalyst-main@<12 hex>` and `catalyst-admin@<12 hex>` (tree hashes at Netlify build time; `SENTRY_RELEASE` is only an override), and the phone `com.catalystgrowth.platform@<version>+<build>` with `dist` = build. A dirty tree adds `-dirty`. Source maps: hidden, uploaded only when `SENTRY_AUTH_TOKEN` is present, deleted after upload, the build fails if one is left. **The upload itself has not been run with a real token.** Native source maps and dSYMs are not uploaded yet (tech-debt row 34).

## Alerts and uptime

- **Email to Pedram only.** No Slack, no PagerDuty: **not built.** The colleague owns the box and the Netlify sites, so Pedram relays an outage to him.
- Error alert rules ("new issue in production", "issue regression") are **created after a week of watching volume**; none exist yet.
- Uptime: Sentry's one included monitor on the API `/up`; the two web origins on one free external monitor service (**not chosen**). `/up` proves nginx, php-fpm and the framework only (tech-debt row 37). Sentry Crons is deferred (row 38).

## What lives here

Only this README. Sentry-as-code ([Sentry's Terraform provider](https://registry.terraform.io/providers/jianyuan/sentry/latest/docs)) was not adopted.
