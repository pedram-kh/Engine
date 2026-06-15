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
| S2             | EU_LANGUAGES registry + UI_LOCALES + PHP enum + validation split + 5.25 parity  | Done    |
| S2b            | Lazy on-demand locale loading (both SPAs)                                       | Done    |
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

---

## S2 — Registry, PHP enum, validation split, parity

The single source of truth for the locale sets, with the validation split (Q2) wired across both stacks. `availableLocales` stays at 3 (the rendered set) — the registry is in place but the dropdown-lighting flip is deferred to S8.

### The registry (frontend SOT)

New [packages/api-client/src/locales.ts](../../packages/api-client/src/locales.ts), exported from the package barrel:

- `EU_LANGUAGES` — all 24 EU codes (ISO 639-1), the **content-language** set.
- `UI_LOCALES` — the rendered subset (`['en','pt','it']` today; one-line flip to the full 24 at S8).
- `PreferredLanguage` (in `types/user.ts`) now **derives** from `UiLocale` instead of a hand-written `'en'|'pt'|'it'`, so it widens automatically at the flip. `EuLanguage` is the distinct content-language type.
- `LANGUAGE_ENDONYMS` + `euLanguageOptions()` + `languageEndonym()` — endonym (autonym) labels, locale-neutral, so content-language pickers and read-only displays render all 24 consistently **without** a 24x24 translated-label matrix. Options are ordered English-first, then by endonym (`Intl.Collator`).

### The PHP enum (backend SOT mirror)

New [apps/api/app/Core/Enums/Locale.php](../../apps/api/app/Core/Enums/Locale.php) — 24 backed cases mirroring `EU_LANGUAGES`, a typed `UI_LOCALES` constant mirroring the TS one, and `values()` / `uiValues()` helpers. (First enum in `app/Core/Enums`.)

### The validation split (Q2)

- **`preferred_language` -> UI_LOCALES (rendered subset).** `SignUpRequest` (`in:en,pt,it` -> `Rule::in(Locale::UI_LOCALES)`) and `SignUpService::normaliseLanguage()`. A UI locale we cannot render is rejected, so a stored value is never a silent `en` lie. `SignUpTest`'s `'fr' -> 422` assertion stays green (fr is EU but not rendered).
- **content-language -> EU_LANGUAGES (all 24).** `UpdateProfileRequest` + `AdminUpdateCreatorRequest` (`primary_language`, `secondary_languages.*`: `size:2` -> `Rule::enum(Locale::class)`, keeping the cross-layer rule-parity contract intact — `AdminUpdateCreatorRequestRuleParityTest` green); agency/brand `default_language` in `UpdateAgencySettingsRequest`, `CreateBrandRequest`, `UpdateBrandRequest` (`Rule::in(['en','pt','it'])` -> `Rule::enum(Locale::class)`). The agency/brand 422 tests were repointed: a **non-EU** code (`ja`) is the new negative case, plus an added positive that a non-UI EU code (`fr`) is now accepted — giving the widening real teeth.
- **Carve-out left untouched:** `CreatorWizardController::getContractTerms` keeps `['en','pt','it']` — that is contract-locale negotiation for legally-binding content (only `master-agreement.en.md` exists; the carve-out keeps it English), not the UI registry.

### The 4 content-language dropdowns (+ the display surfaces they feed)

All content-language INPUT pickers now derive from `euLanguageOptions()` (24, endonym-labelled): `BrandForm.vue`, agency `SettingsPage.vue`, wizard `Step2ProfileBasicsPage.vue`, admin `field-edit.ts`. Because the pickers can now store any of 24, the read-only **display** surfaces and **filter** dropdowns that previously hardcoded a 6-code list + `app.roster.languages.*` keys were unified onto the same registry (`languageEndonym` / `euLanguageOptions`) to avoid shipping raw-code labels: `CreatorRosterPage.vue`, `DiscoverPage.vue`, agency + discover `CreatorDetailPage`/`DiscoverProfilePage`, and admin `CreatorDetailPage.vue`. This is the only coherent end-state once a non-en/pt/it language is selectable; the "4 dropdowns" scope necessarily pulled in their matching displays. Admin `field-edit` language select dropped `allowCustomCode` (the 24-list is exhaustive + backend-enforced). The 6 UI **locale switchers** were not touched per-file — they derive from `availableLocales`, which now derives from `UI_LOCALES`.

### Parity tests (5.25)

- [packages/api-client/src/locales.spec.ts](../../packages/api-client/src/locales.spec.ts) — source-inspects `Locale.php` (no eval): enum cases == `EU_LANGUAGES`, `Locale::UI_LOCALES` == TS `UI_LOCALES`; plus registry-integrity checks (24, no dupes, subset, endonym coverage, option ordering).
- [apps/api/tests/Unit/Core/LocaleEnumTest.php](../../apps/api/tests/Unit/Core/LocaleEnumTest.php) — PHP-side catalogue tripwire pinning the 24 cases + UI subset independently.

**Break-revert (5.35):** dropped the `Swedish` case from `Locale.php` -> `locales.spec.ts` "enum cases match EU_LANGUAGES" failed as expected -> restored the case (the enum is a new untracked file, so restore was a manual re-add, confirmed by re-running the spec to green, not `git checkout`).

**Done-gate (S2):** api-client 106, main 1043, admin 387 (all green); frontend typecheck + ESLint clean (2 pre-existing `v-html` warnings only); backend 105 affected feature/unit tests green; Pint + Larastan L8 clean; Prettier clean.

---

## S2b — Lazy on-demand locale loading (both SPAs)

The blocker for scaling to 24: both bootstraps statically imported and spread **every** locale's JSON into one `messages` object (confirmed in S1 against [apps/main/src/core/i18n/index.ts](../../apps/main/src/core/i18n/index.ts)). At 24 that bundles 23 unused locales into the initial payload. S2b converts both SPAs to eager-`en` + lazy-everything-else, so the initial payload carries one locale's strings regardless of how large the rendered set grows.

### The loader (per-SPA bootstrap)

- `en` is the only statically-imported locale (the always-needed `fallbackLocale`; present synchronously at boot, no missing-key flash). The `createI18n` `messages` now contains just `{ en }` (cast to the all-locales schema generic; the rest are merged at runtime).
- `loadLocaleMessages(locale)` uses `import.meta.glob('./locales/*/*.json')` so Vite emits **one async chunk per namespace JSON**; only the active locale's files are ever fetched. Main spreads the namespaces; admin reuses `deepMergeLocale` (the `admin.*`-subtree merge the eager `en` bundle already used), so lazy and eager paths build identical shapes.
- `setLocale(locale)` is the single switch point: if the target is not yet loaded (and not `en`), it `await`s `loadLocaleMessages` and `setLocaleMessage`s it **before** flipping `i18n.global.locale`, so the UI never paints against a half-populated bundle.

### The switchers (no-flash on user switch)

The 6 locale `<v-select>`s (`v-model="locale"` -> `:model-value="locale"` + `@update:model-value="selectLocale"`) now route through a new `useLocaleSwitch()` composable in each SPA's `core/i18n`. Critically it operates on the instance from `useI18n()` — the bootstrap singleton in production, the **per-test** instance under Vitest — so it works in both and does not couple components to the singleton. Already-loaded locales (the common case) flip **synchronously** (no `await` taken), preserving existing v-model semantics; only a first-ever switch to an unloaded locale awaits the import. Switchers covered: main `auth/AuthLayout`, `AgencyLayout`, `OnboardingLayout`, `CreatorDashboardLayout`; admin `auth/AuthLayout`, `AdminLayout`.

### The boot seam (prepares S5)

Both `main.ts` files now `await setLocale(i18n.global.locale.value)` before `app.mount` — the resolve-target-then-preload-then-mount seam. The target is `en` today (a no-op preload); S5 swaps in persistence-based target resolution here, and the await-before-mount guarantee is already in place so a persisted non-`en` boot will not flash English.

**Grounding notes for the architect:**

- Code-splitting is real, not just typed: `pnpm --filter ./apps/main build` emits paired per-locale namespace chunks (`auth-*.js`, `app-*.js`, `creator-*.js`, …, two of each = pt + it) while `en` stays inlined in the main `index` bundle (it is also statically imported, so the glob's `en` entry is deduped into the main chunk, never fetched separately).
- The `legacy: false` type arg was made explicit on `createI18n<[Schema], Locales, false>` so `i18n.global` narrows to a `Composer` (giving `.locale.value` + a schema-typed `setLocaleMessage`); without it the global is the `Composer | VueI18n` union and `.value` does not type-check.

**Done-gate (S2b):** frontend typecheck green (both SPAs); ESLint clean (2 pre-existing `v-html` warnings only); main 1043 + admin 387 tests green (switcher specs unchanged and passing — synchronous-flip semantics preserved); `apps/main` production build green with confirmed per-locale chunking. No backend surface touched.
