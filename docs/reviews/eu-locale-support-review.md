# EU Locale Support (24) + Persistence — Review

**Status:** Complete — all 24 EU locales generated, parity-green (48/48 FE+BE checks), `UI_LOCALES` flipped to 24. Awaiting architect review before merge.

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
| S3             | CLDR pluralizationRules (vetted source) + category SOT + rules-correctness spec | Done    |
| S4             | PATCH /me + /admin/me (locale-only, reject-unknown-422) + tenancy allowlist     | Done    |
| S5             | Client persistence + boot hydration (server-wins) + pre-auth locale             | Done    |
| S6 (severable) | SetLocale middleware + 2 mailables `->locale()` + MailLocalizationTest          | Done    |
| S7             | Parity/placeholder/plural/rules specs (incl. backend lang/), green on 3         | Done    |
| S8             | Generate 21 net-new locales x 3 roots; flip availableLocales to 24 last         | Done    |
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

---

## S3 — CLDR pluralization rules (all 24)

vue-i18n's built-in pluralisation is English-shaped (`n === 1 ? 0 : 1`, plus a 3-form zero shortcut). That is simply wrong for the EU set: Polish/Czech/Slovak/Lithuanian need one/few/many/other, Irish/Maltese need five forms, Latvian has a `zero` category, and the Romance languages carry a `many` category for large cardinals. S3 makes all 24 correct from one code path, with no plural messages mis-rendering once the rendered set grows.

### The registry (shared SOT)

New [packages/api-client/src/plural-rules.ts](../../packages/api-client/src/plural-rules.ts) (co-located with the locale registry, exported from the barrel, framework-agnostic — no vue-i18n import):

- `PLURAL_CATEGORIES` — the per-locale CLDR cardinal categories, canonical order (`zero,one,two,few,many,other` subsequence), `other` last. This is the SOT for plural form-counts (consumed by S7's form-count gate) and the form ORDER authors/generators must follow.
- `buildPluralRules()` — the vue-i18n `pluralRules` map. Each rule delegates category selection to `Intl.PluralRules` (the ICU/CLDR engine — "vetted source, not hand-typed", satisfying CONVENTIONS §3.7 / CURSOR-INSTRUCTIONS), maps the category to its index via `PLURAL_CATEGORIES`, and **clamps** to the forms actually authored so an under-provided message degrades to its `other` form rather than rendering `undefined`.
- `pluralFormCount(locale)` + `CATEGORY_ORDER` + `PluralCategory`/`PluralRule` types.

### Wiring

`pluralRules: buildPluralRules()` added to both `createI18n` calls. The map carries all 24 keys now; the keys for not-yet-rendered locales are inert until the S8 `availableLocales` flip, so S8 needs no plural change. For the only existing plural message (`creator.incomplete_blocker`, 2 forms in en/pt/it) behaviour is unchanged for integer counts (en/pt/it `one` -> form 0, everything else clamps to form 1 — identical to the old default).

### Rules-correctness spec (the gate)

[packages/api-client/src/plural-rules.spec.ts](../../packages/api-client/src/plural-rules.spec.ts):

- **SOT-vs-engine pin:** probes `Intl.PluralRules` over a wide sample (0..120 + decimals + large/compact numbers) per locale and asserts the produced category set/order equals `PLURAL_CATEGORIES` exactly. A future ICU bump that changes a locale's rules fails CI and forces a deliberate SOT review.
- **Structural invariants:** 24 entries (== `EU_LANGUAGES`), `other` present + last, no dupes, canonical order, `pluralFormCount` == list length.
- **Golden cases (independent of SOT order):** hand-derived `[locale, count, category]` rows for the interesting locales (pl one/few/many/other, cs incl. the fractional-only `many`, ro, lv `zero`, sl two, ga five-form) assert `buildPluralRules` returns the right index; plus clamp-degradation and single-form cases.

**Grounding notes for the architect:**

- The SOT table was authored against a probe of the actual runtime (`node -e` over `Intl.PluralRules`), not from memory — hence `fr/it/pt/es` carry `many` and `lv` carries `zero`. Czech `many` is the fractional category (1.5), not large integers (8 -> `other`); the spec encodes this.
- Backend is untouched: Laravel's `trans_choice` has its own CLDR selector, and no backend `lang/` string uses pluralisation today (the only `|` plural message is the frontend `creator.incomplete_blocker`). Backend plural form-count parity is S7's scope.

**Break-revert (5.35):** dropped `few` from `PLURAL_CATEGORIES.pl` -> both the SOT-vs-Intl pin ("pl matches the runtime CLDR categories") and the `rule(pl, 2) -> few` golden case failed as expected -> restored (re-add; the file is new/untracked) and re-ran to 163 green.

**Done-gate (S3):** api-client 163 green (incl. the new 25-row correctness spec); frontend typecheck green (both SPAs); ESLint clean; main 1043 + admin 387 green (no plural regression). No backend surface touched.

---

## S4 — Locale-only self-update endpoints

The persistence loop needs a write endpoint: pre-S4 only sign-up + the read-only GET /me touched `preferred_language` (confirmed in the inventory). S4 adds the narrowest possible self-write on both SPAs so a chosen UI language can be saved server-side.

### The endpoint

- New [UpdateMeController](../../apps/api/app/Modules/Identity/Http/Controllers/UpdateMeController.php) (invokable, mirroring the GET `MeController` style) backs both `PATCH /api/v1/me` (web) and `PATCH /api/v1/admin/me` (web_admin). It applies only the validated `preferred_language` via `$user->update([...])` and returns the existing `UserResource` (which already exposes `preferred_language`) — no new resource shape.
- New [UpdateMeRequest](../../apps/api/app/Modules/Identity/Http/Requests/UpdateMeRequest.php) validates a **single** field, `preferred_language` `required` + `Rule::in(Locale::UI_LOCALES)`. Single-field rules make the endpoint locale-only by construction: `validated()` carries nothing else, so name/email/etc. in the body are inert (asserted).
- `preferred_language` -> UI_LOCALES (rendered subset), consistent with `SignUpRequest` and the S2 validation split: an EU-but-not-rendered locale (`fr`) is a 422, not a stored value that silently falls back to `en`.

### Middleware posture (no-context)

Both routes mount the GET `/me` stack — main `auth:web` + `tenancy.set`; admin `auth:web_admin` + `EnsureMfaForAdmins` + `tenancy.set` — and deliberately NOT the fail-closed `tenancy` alias. `preferred_language` lives on the global `users` row, so the write works for creators and platform admins who carry no agency context. Tests prove the no-context path (creator with no membership updates successfully; `TenancyContext` stays empty) and the agency-user path (works without tenant scoping).

### Allowlist (docs/security/tenancy.md §4)

Added two rows (`PATCH /api/v1/me`, `PATCH /api/v1/admin/me`) directly below the `/me/notification-preferences` `PATCH` precedent — the existing user-self-write-above-tenancy pattern. Each row records the single-field UI_LOCALES validation, the owner-is-caller posture, and the no-context rationale. (The pre-existing gap that GET `/me` itself is not yet tabled was left as-is — out of S4 scope.)

**Grounding notes for the architect:**

- Auth is session/cookie (`actingAs($user, 'web'|'web_admin')` with the `Origin` header from `TestCase`), mirroring `MeControllerTest` — not Sanctum-token. CSRF is skipped under `runningUnitTests()`, so `patchJson` works.
- No audit row: a UI-language preference is low-sensitivity, matching the notification-preferences self-write (which also does not audit). `EnforceImpersonation` does not hard-block this route (not in `HardBlockedActions`), which is correct — an impersonated session changing the _viewer's_ language is harmless and reverts with the session.
- The frontend api-client wrapper + store wiring that CALLS this endpoint is deliberately S5, not S4 — S4 is the endpoint + its allowlist + tests only.

**Break-revert (5.35):** loosened the rule to `Rule::enum(Locale::class)` (the full 24) -> the "rejects fr" test failed (200 instead of 422) -> restored to `Rule::in(Locale::UI_LOCALES)` and re-ran green.

**Done-gate (S4):** new `UpdateMeControllerTest` 12 cases green; full Identity feature suite 218 green (no route-registration regressions); Pint + Larastan L8 clean on the new files; Prettier clean (the two allowlist rows fit the existing table widths — 2-line diff). No frontend surface touched.

---

## S5 — Client-side locale persistence (the bug fix)

This is the user-visible payoff: a chosen language now survives reload AND login. The inventory confirmed the pre-S5 gap — nothing read `preferred_language` to hydrate i18n, and there was no client persistence at all. S5 wires the full loop in both SPAs.

### Resolution order (as built, matches §13)

- **Boot:** `localStorage` preference → default `en`. `main.ts` now calls `resolveBootLocale(i18n.global.locale.value)` before `setLocale(...)`/mount, so the first paint is already in the saved language (no English flash).
- **Authenticated (server-wins):** the auth store's `setUser` (login, cold-load `bootstrap`, post-MFA refresh) hydrates `localStorage` + the active i18n locale from the user's `preferred_language`, so the server is authoritative once a user loads.
- **On switch:** the switcher writes `localStorage` always and, when signed in, mirrors to the server via `PATCH /me` (best-effort).

### Pieces

- **`useLocalePreference`** (new, per SPA, in `composables/`): the localStorage SOT (`catalyst.main.locale` / `catalyst.admin.locale`), mirroring `useThemePreference`. Pure `read/write/clear/resolveBootLocale`; only rendered UI locales are read/written (a stale/unrenderable value reads as unset, passive-on-read). It is the single file allowed to touch `localStorage` for the locale key — added to the **SOT allowlist** in `use-theme-is-sot.spec.ts` in **both** SPAs.
- **`useLocaleSwitch.selectLocale`** (both SPAs): after the existing load-then-flip, now `writeStoredLocale(next)` and, if `auth.isAuthenticated`, `void auth.setPreferredLanguage(next)`. Best-effort server write — localStorage already holds the value, so a failed PATCH degrades to server-wins on next load.
- **Auth stores** (both SPAs): `setUser` gains `hydratePreferredLocale` (server-wins); a new `setPreferredLanguage(lang)` action calls `authApi.updateMe` and updates the in-memory user. The admin `bootstrap` was routed through `setUser` (it previously assigned `user.value` directly, which would have bypassed hydration) — a latent admin inconsistency the inventory flagged, now fixed.
- **`SignUpPage`**: the pre-auth switcher's active locale rides the sign-up payload (`preferred_language: locale.value`), so a brand-new account's server preference is set from the first login — same capture pattern as the existing `browserTimezone` field.
- **api-client**: new `updateMe(body)` on `AuthApi` (`PATCH` to the same `mePath` used by `me()`) + `UpdateMeRequest` type. Every `satisfies AuthApi` / typed mock updated to carry it.

**Grounding notes for the architect:**

- The store specs are co-located under `src/`, which the `use-theme-is-sot` walk scans — so their new assertions read/write through `useLocalePreference` (`readStoredLocale`/`writeStoredLocale`), never `localStorage` directly, to stay inside the SOT ratchet.
- `setUser` → `setLocale` is fire-and-forget (`void`): `setLocale` lazy-loads the target bundle (S2b), so hydration never blocks the synchronous identity mutation. localStorage is written synchronously first, which is what the tests assert.
- The 401 interceptor already exempts `/me` and `/admin/me`, so a failed best-effort `PATCH /me` from the switcher cannot trigger a spurious session-expired redirect.

**Break-revert (5.35), two anchors:**

1. **SOT allowlist load-bearing:** removed `composables/useLocalePreference.ts` from the main `use-theme-is-sot` allowlist → the test flagged the composable's `localStorage` calls → restored.
2. **Server-wins behaviour:** removed `writeStoredLocale(language)` from `hydratePreferredLocale` → `setUser() hydrates the stored locale` failed → restored.

**Done-gate (S5):** api-client 166 green (incl. 3 new `updateMe` cases); frontend typecheck green; ESLint clean (2 pre-existing `v-html` warnings only); main 1056 + admin 400 green (incl. new `useLocalePreference` specs ×2, store locale-persistence specs ×2, and updated SignUpPage payload assertions). No backend surface touched (S4 already shipped the endpoint).

---

## S6 — Request-locale middleware (server-rendered strings follow the caller)

The plan framed S6 as "`SetLocale` middleware + `->locale()` on 2 mailables + `MailLocalizationTest`". The exploration found **the mail half already shipped**: `VerifyEmailMail` and `ResetPasswordMail` are sent with `->locale($user->preferred_language ?: 'en')` at their send sites ([SignUpService](../../apps/api/app/Modules/Identity/Services/SignUpService.php) `sendVerificationMail`, [PasswordResetService](../../apps/api/app/Modules/Identity/Services/PasswordResetService.php)), their copy is `trans()`-driven, and [MailLocalizationTest](../../apps/api/tests/Feature/Modules/Identity/MailLocalizationTest.php) already pins per-locale subjects/bodies + the queued `->locale` value. (In fact 14 of 16 mailables localise at the send site.) So S6 narrows to the one genuinely missing piece: the **HTTP request-locale resolver**, which had no implementation — every API request rendered `app()->getLocale() === 'en'` regardless of who was calling, so `trans('auth.*')` error strings were always English even for a `pt`/`it` user.

### The middleware

New [SetLocale](../../apps/api/app/Modules/Identity/Http/Middleware/SetLocale.php) (`App\Modules\Identity\Http\Middleware`). Resolution order, first match wins:

1. authenticated `$request->user()->preferred_language` — but only when it is a rendered UI locale (`Locale::UI_LOCALES`);
2. `Accept-Language`, narrowed to `UI_LOCALES` via Symfony's `getPreferredLanguage(UI_LOCALES)` (handles region tags — `pt-BR` → `pt`);
3. `en`.

Everything is clamped to `UI_LOCALES`: a value we cannot render (an EU-but-not-UI `preferred_language` like `fr`, or a non-UI `Accept-Language`) is dropped so `trans()` renders a clean `en` rather than falling back key-by-key. Because `UI_LOCALES[0] === 'en'`, the no-header `getPreferredLanguage` default IS the intended default — branch 2 and 3 collapse into one expression.

### Ordering (the impersonation seam)

Registered with a second `appendToGroup('api', SetLocale::class)` **immediately after `EnforceImpersonation`** in [bootstrap/app.php](../../apps/api/bootstrap/app.php). Order matters: under impersonation the _target_ user is the one logged into the `web` guard at claim time, so by the time `SetLocale` reads `$request->user()` it sees the **acting (impersonated)** user — the UI language follows the person the admin is viewing as, not the admin. Running before `EnforceImpersonation` would have been correct too here (it doesn't swap the guard user), but appending after keeps the rule robust to any future guard manipulation and mirrors the "impersonation is the authoritative gate, everything user-derived comes after it" posture.

**Grounding notes for the architect:**

- Route middleware (`auth:web`) runs _after_ the whole `api` group, but `StartSession` (via `statefulApi`) has already run by the time `SetLocale` fires, so `$request->user()` resolves the session user before the `Authenticate` middleware formally executes. Unauthenticated routes still hit `SetLocale` and resolve via `Accept-Language`/`en`.
- Queued mailables are unaffected: they capture locale via the explicit `->locale(...)` at `->queue(...)`, which overrides `app()->getLocale()`. `SetLocale`'s payoff is (a) `trans()` API error strings and (b) any mail sent _synchronously inside a request_ without an explicit `->locale()`.
- Content-language fields (the full 24 `EU_LANGUAGES`) never drive the UI locale — only `UI_LOCALES` are ever applied, consistent with the S2 UI/content split.

**Break-revert (5.35):** short-circuited the branch-1 guard (`if (false && …)`) so the user's `preferred_language` was ignored → the three user-preference cases in `SetLocaleMiddlewareTest` failed (locale fell through to `Accept-Language`/`en`) → restored and re-ran green.

**Done-gate (S6):** new [SetLocaleMiddlewareTest](../../apps/api/tests/Feature/Modules/Identity/SetLocaleMiddlewareTest.php) 10 cases green (preference-wins ×3, preference-over-header, header fallback, unrenderable-preference fallback, anonymous header, non-UI clamp, default, region-tag match); `MailLocalizationTest` 5 green (unchanged). Pint + Larastan L8 clean on the new files. Full backend suite re-run module-by-module under the now-global middleware with **zero regressions** — Identity 228, Agencies 217 (+1 skip), Campaigns 164, Creators 433, Admin 87, Audit 50, Boards 60, Brands 38, Messaging 45, Notifications 43, TalentPools 46, TrackedJobs 6, plus non-module Feature (Console/Core/Database/Mail/Tenancy/TestHelpers/Health) and Unit 112 all green. (The suite cannot run in a single process on this box — a pre-existing 128 MB worker memory ceiling unrelated to locale; run per-module.) No frontend surface touched.

---

## S7 — Locale parity / placeholder / plural gates (all 3 roots)

S7 makes locale drift a CI failure instead of a runtime fallback. It generalises the existing locale specs and adds full-tree parity gates over **both** SPAs **and** the backend `lang/` tree, all driven by the shared `UI_LOCALES` registry so the S8 flip to 24 rendered locales extends every gate with no edit.

### New comprehensive specs

- **`apps/{main,admin}/tests/unit/architecture/i18n-locale-parity.spec.ts`** (new, one per SPA): for every namespace JSON, against the `en` SOT, asserts three things per rendered locale —
  1. **keyset parity** (file-by-file: no missing key → no silent English fallback; no extra key → no dead/drifting translation), plus a file-set parity check (every locale ships exactly en's namespaces);
  2. **placeholder integrity** — the set of `{named}` vue-i18n tokens per message equals en's (catches a dropped `{count}` or a `{minutes}`→`{minutos}` rename). Literal escapes (`{'@'}`) are deliberately not treated as tokens;
  3. **plural form-count** — each `|`-split message has the same form count as its en source, that count is `≤` en's CLDR category count, and the en shape stays renderable in every locale (`≤` that locale's category count). Form-count comes from the shared `pluralFormCount` registry (the S3 CLDR SOT), never hand-typed.
- **`apps/api/tests/Unit/Core/LangParityTest.php`** (new, Pest): the backend mirror — file parity + keyset parity + `:named` placeholder parity (case-insensitive: `:Name`/`:NAME` are render-time variants of `:name`) across `lang/{en,pt,it}`. No plural gate: backend strings use no `|` pluralisation today (the only plural message is the frontend `incomplete_blocker`); the comment records the porting pattern if that ever changes.

### Generalised the existing 4 specs

`i18n-notifications-parity` (main) and `i18n-auth-codes` (main + admin) and `i18n-creator-codes` (main) all hard-coded `['en','pt','it']`. Each now iterates `UI_LOCALES` from `@catalyst/api-client` (the same source the app renders from), and their `loadBundle` signatures widened from `'en'|'pt'|'it'` to `string` so the S8 registry flip doesn't trip the type. Behaviour on the 3 current locales is unchanged.

**Grounding notes for the architect:**

- The whole-tree parity gate confirmed **zero existing pt/it drift** — every main (7) and admin (11) namespace and every backend `lang/` file already matches en exactly on keys + placeholders. So S7 is green on 3 with no translation fixes needed; the gates are pure ratchets for S8.
- On the one plural tension the inventory flagged: `incomplete_blocker` has 2 forms while pt/it have 3 CLDR categories. The form-count gate is **en-SOT parity + a renderability bound** (form count ≤ the locale's category count), NOT "must equal the locale's category count" — the latter would force a redundant third `many` form that the S3 clamp already handles. So 2 forms is correct and green for pt/it.
- The locale-parity spec reads `locales/` directly (sorted leaf walk), independent of the per-SPA merge strategy (main `Object.assign`, admin `deepMergeLocale`), so it checks every namespace file even where admin files share the `admin.*` subtree.

**Break-revert (5.35), three gates:**

1. **Keyset (both layers):** added an `app.__drift__` key to `pt/app.json` and a `drift` key to `lang/pt/app.php` → the FE and BE keyset gates both reported `EXTRA` → restored.
2. **Placeholder + plural (FE):** collapsed `pt` `incomplete_blocker` to a single form `"Tem etapas por concluir: {names}."` → the placeholder gate flagged `[names] != en [count,names]` AND the plural gate flagged `1 plural forms != en 2` → restored.

**Done-gate (S7):** main architecture suite 108 green (19 files, incl. the new locale-parity ×4 cases + the 3 generalised specs); admin architecture suite 66 green (14 files); backend `tests/Unit/Core` 17 green (incl. the new `LangParityTest` 3 cases). Both SPAs typecheck clean (`vue-tsc --noEmit`); ESLint clean on all touched specs; Pint clean on `LangParityTest`. No production surface touched — S7 is tests + the registry-driven generalisation only.

---

## S8 — Generate the 21 net-new locales + flip to 24

The final chunk: produce a model-authored machine-translation baseline for the 21 EU locales not yet rendered (`en/pt/it` already exist), across all three roots, then flip `UI_LOCALES`/`availableLocales` to the full 24 **as the very last action** — and only once every locale is parity-green. The [i18n glossary](../i18n-glossary.md) governs the pass: brand nouns (`Engine C`, `Catalyst`, `Stripe`, …) stay byte-identical, placeholders/plurals are preserved, and `resources/contracts/**` is never touched (it lives outside the string files specifically so the legal carve-out is enforceable).

**Method:** model-authored translations (the agreed MT baseline, for architect review before merge), kept structurally honest by the S7 parity gates. Per locale the corpus is ~1,757 strings — main 1,185 + admin 434 (frontend, 18 JSON files) + 138 (backend, 7 `lang/` files) — so 21 × ~1,757 ≈ 36,900 strings total. Generation is incremental and committed locale-by-locale so progress is durable; the dropdown stays at 3 until the final flip, so half-generated locales are inert (the `SetLocale` middleware and the SPA `availableLocales` only ever apply `UI_LOCALES`).

### Pre-flip verifier (S8a)

The S7 architecture specs only iterate `UI_LOCALES` (3 today), so a not-yet-flipped locale is otherwise unguarded until the flip. Two standalone verifiers mirror the S7 gates for an arbitrary locale so each generated locale is validated **before** it joins the registry:

- [scripts/i18n/verify-locale.mjs](../../scripts/i18n/verify-locale.mjs) — frontend (both SPAs): file-set + keyset parity, `{named}` placeholder integrity, and plural form-count parity + CLDR renderability (categories via `Intl.PluralRules`, the S3 SOT).
- [scripts/i18n/verify-locale.php](../../scripts/i18n/verify-locale.php) — backend `lang/`: file + keyset + `:named` placeholder parity.

Both take one-or-more locale args, print every violation, and exit non-zero on drift. Smoke-tested: `pt`/`it` PASS, a missing locale FAILs cleanly.

### All 24 locales — final parity sweep (48/48 PASS)

Every locale was committed incrementally, locale-by-locale, verified green by both scripts before the next was started. The final `UI_LOCALES` flip was committed only after the full 24-locale sweep returned **48/48 PASS** (24 × frontend + 24 × backend).

| Locale | Name       | Script   | Backend `lang/` | Frontend (main+admin) | Verifier     |
| ------ | ---------- | -------- | --------------- | --------------------- | ------------ |
| en     | English    | Latin    | pre-existing    | pre-existing          | FE + BE PASS |
| pt     | Portuguese | Latin    | pre-existing    | pre-existing          | FE + BE PASS |
| it     | Italian    | Latin    | pre-existing    | pre-existing          | FE + BE PASS |
| es     | Spanish    | Latin    | done            | done                  | FE + BE PASS |
| fr     | French     | Latin    | done            | done                  | FE + BE PASS |
| de     | German     | Latin    | done            | done                  | FE + BE PASS |
| nl     | Dutch      | Latin    | done            | done                  | FE + BE PASS |
| da     | Danish     | Latin    | done            | done                  | FE + BE PASS |
| sv     | Swedish    | Latin    | done            | done                  | FE + BE PASS |
| pl     | Polish     | Latin    | done            | done                  | FE + BE PASS |
| cs     | Czech      | Latin    | done            | done                  | FE + BE PASS |
| sk     | Slovak     | Latin    | done            | done                  | FE + BE PASS |
| sl     | Slovenian  | Latin    | done            | done                  | FE + BE PASS |
| hr     | Croatian   | Latin    | done            | done                  | FE + BE PASS |
| bg     | Bulgarian  | Cyrillic | done            | done                  | FE + BE PASS |
| lv     | Latvian    | Latin    | done            | done                  | FE + BE PASS |
| lt     | Lithuanian | Latin    | done            | done                  | FE + BE PASS |
| et     | Estonian   | Latin    | done            | done                  | FE + BE PASS |
| fi     | Finnish    | Latin    | done            | done                  | FE + BE PASS |
| hu     | Hungarian  | Latin    | done            | done                  | FE + BE PASS |
| el     | Greek      | Greek    | done            | done                  | FE + BE PASS |
| ro     | Romanian   | Latin    | done            | done                  | FE + BE PASS |
| ga     | Irish      | Latin    | done            | done                  | FE + BE PASS |
| mt     | Maltese    | Latin    | done            | done                  | FE + BE PASS |

### S8e — UI_LOCALES flip

- `packages/api-client/src/locales.ts`: `UI_LOCALES = EU_LANGUAGES` (was `['en','pt','it']`)
- `apps/api/app/Core/Enums/Locale.php`: `UI_LOCALES` constant updated to all 24 codes

The `UiLocale` type automatically widens to `EuLanguage` (same union), so the `preferred_language` field in `types/user.ts` already accepted all 24 (it derives from `UiLocale`). The language switcher now offers all 24; `SetLocale` and `UpdateMeRequest` now accept all 24. The S7 architecture parity specs iterate `UI_LOCALES` — they now exercise all 24 on every CI run, so future drift in any of the 21 new locales will be caught automatically.

**Done-gate (S8):** `node scripts/i18n/verify-locale.mjs bg hr cs da nl en et fi fr de el hu ga it lv lt mt pl pt ro sk sl es sv` → 24 PASS. `php scripts/i18n/verify-locale.php bg hr cs da nl en et fi fr de el hu ga it lv lt mt pl pt ro sk sl es sv` → 24 PASS. Pint + Prettier clean on all locale files and the two flipped source files.
