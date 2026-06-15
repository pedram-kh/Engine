# EU Locale Support (24) + Persistence — Review

**Status:** In progress (drafted by Cursor as the build proceeds; load-bearing claims to be verified by the architect against the actual test code before merge).

**Reviewed against:** the pre-kickoff i18n inventory; the approved sub-step plan (`.cursor/plans/eu_locale_support_+_persistence_790b68de.plan.md`) with the five locked answers (Q1–Q5) and the added S2b; `PROJECT-WORKFLOW.md` (chunk lifecycle + standards: constant-parity, source-inspection, break-revert claim-verification, SOT-allowlist discipline); `00-MASTER-ARCHITECTURE.md` §13; `CURSOR-INSTRUCTIONS.md` §10/§12.7; `02-CONVENTIONS.md` §3.7/§10.8; `docs/security/tenancy.md` §4.

**Goal (one chunk):** (a) make a selected UI language survive reload/login in both SPAs; (b) expand UI + content languages from `{en, pt, it}` to all 24 official EU languages with a machine-translation baseline, legally-binding content carved out to English.

**Locale set (24, ISO 639-1, all fit `char(2)`):** `bg, hr, cs, da, nl, en, et, fi, fr, de, el, hu, ga, it, lv, lt, mt, pl, pt, ro, sk, sl, es, sv`.

---

## Sub-step ledger

| Sub-step       | Title                                                                           | Status  |
| -------------- | ------------------------------------------------------------------------------- | ------- |
| S1             | Docs-first SOT updates + glossary                                               | Done    |
| S2             | EU_LANGUAGES registry + UI_LOCALES + PHP enum + validation split + 5.25 parity  | Pending |
| S2b            | Lazy on-demand locale loading (both SPAs)                                       | Pending |
| S3             | CLDR pluralizationRules (vetted source) + category SOT + rules-correctness spec | Pending |
| S4             | PATCH /me + /admin/me (locale-only, reject-unknown-422) + tenancy allowlist     | Pending |
| S5             | Client persistence + boot hydration (server-wins) + pre-auth locale             | Pending |
| S6 (severable) | SetLocale middleware + 2 mailables `->locale()` + MailLocalizationTest          | Pending |
| S7             | Parity/placeholder/plural/rules specs (incl. backend lang/), green on 3         | Pending |
| S8             | Generate 21 net-new locales x 3 roots; flip availableLocales to 24 last         | Pending |
| S9 (severable) | Shared locale-aware currency/date util                                          | Pending |

Dependency: S2 -> S2b -> {S5, S7} -> S8. The risky `availableLocales` -> 24 flip is the LAST action of S8.

---

## S1 — Docs-first SOT updates + glossary

Docs were updated **before** any code, so the source of truth describes the as-built target rather than the stale eager-load/3-locale posture. No code touched; the only applicable done-gate for a markdown-only step is Prettier, which is green.

**Changes:**

- **[00-MASTER-ARCHITECTURE.md](../00-MASTER-ARCHITECTURE.md) §13** — rewritten to the as-built/target state: 24-locale UI set with the single `EU_LANGUAGES` registry (+ parallel PHP `Locale` enum, constant-parity test); the `UI_LOCALES` vs `EU_LANGUAGES` split (Q2: `preferred_language` validates against the rendered set, content-language metadata against all 24); the legal carve-out (`resources/contracts/**`, never MT); backend `lang/` corrected to "central, one file per domain, NOT per-module" (was "separate file per module"); frontend lazy-by-active-locale with `en` statically bundled (was "lazy-loaded by route", which never matched the eager `index.ts`); the real two-path resolution order (frontend localStorage→`preferred_language`→`en`; backend `SetLocale` after `EnforceImpersonation`: user→`Accept-Language`→`en`); the new persistence loop via `PATCH /me`. Also updated §1 product summary and the §19 matrix Languages row.
- **[CURSOR-INSTRUCTIONS.md](../CURSOR-INSTRUCTIONS.md) §10 + §12 item 7** — done-gate reframed: author new strings in **`en` only** (source of truth); the 21 non-en locales are generated, parity/placeholder/plural enforced by architecture tests across UI locales **and backend `lang/`** (Q4); legal carve-out and lazy-load notes added. Item 7 no longer says "hand-author en/pt/it per feature".
- **[02-CONVENTIONS.md](../02-CONVENTIONS.md) §3.7 + §10 item 8** — i18n conventions updated to the registry-derived locale set, `en`-authored gate, lazy load, CLDR plural rules; repo-structure `lang/` comment corrected to "central; one folder per locale, one file per domain".
- **[tech-debt.md](../tech-debt.md) (deferred creator-settings entry)** — added a dated update note: the `preferred_language` half is being RESOLVED by this chunk (PATCH endpoint + client persistence + hydration + SetLocale), while the settings page, `timezone` correction, and `theme_preference` persistence (incl. the binary↔tri-state gap) stay deferred. Title narrowed to drop `preferred_language`.
- **[i18n-glossary.md](../i18n-glossary.md) (new)** — do-not-translate brand nouns (`Catalyst Engine`, `Engine C` [grounded at `app.title` in en/pt/it], `Catalyst`, third-party brands); the legal carve-out; consistent domain terms (campaign/creator/draft/roster/escrow/agency/brand/assignment/board); and placeholder/plural integrity rules. This governs the S8 generation pass.

**Grounding notes for the architect:**

- "Eager, not lazy" divergence confirmed against [apps/main/src/core/i18n/index.ts](../../apps/main/src/core/i18n/index.ts):1-115 (every locale statically imported and spread into one `messages` object; `availableLocales: ['en','pt','it']`). §13's prior "lazy-loaded by route" was doc-only.
- "Central, not per-module" backend `lang/` confirmed: files live at `apps/api/lang/{en,it,pt}/{app,auth,creators,campaigns,messages,invitations,mock-vendor}.php` — not under `app/Modules/`.
- `Engine C` is the live `app.title` string and is byte-identical across en/pt/it today, so the do-not-translate rule matches current state.

**Done-gate (S1):** Prettier `--check` green on all five docs. No tests/typecheck/Larastan/Pint applicable (markdown only).
