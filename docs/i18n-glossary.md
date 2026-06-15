# i18n Glossary — translation guardrails

> **Status: Always active reference for any translation work (human or machine).** This is the source of truth for terms that must NOT be translated and domain terms that must be translated consistently across all 24 EU UI locales. It governs the S8 generation pass and any later refinement of `pt`/`it` or the machine-translation baseline.

The supported UI locale set and authoring rules live in [00-MASTER-ARCHITECTURE.md](00-MASTER-ARCHITECTURE.md) §13 and [CURSOR-INSTRUCTIONS.md](CURSOR-INSTRUCTIONS.md) §10. This doc only covers terminology.

---

## 1. Do-not-translate (brand nouns)

These are proper nouns. Keep them **byte-identical** in every locale (including `en`). Do not translate, transliterate, decline, pluralize, or case-fold them.

| Term                    | Notes                                                                                                                                                                                                        |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `Catalyst Engine`       | Full product name.                                                                                                                                                                                           |
| `Engine C`              | Product/app title as rendered in `app.title` ([apps/main/src/core/i18n/locales/en/app.json](../apps/main/src/core/i18n/locales/en/app.json):3 — identical in `pt`/`it`). Must stay identical in all locales. |
| `Catalyst`              | The first agency customer / brand noun (e.g. creator-onboarding welcome copy). Keep as-is even mid-sentence.                                                                                                 |
| Third-party brand names | `Stripe`, `Stripe Connect`, `Meta`, `TikTok`, `YouTube`, `Persona`, `Veriff`, `DocuSign`, `Postmark`, `Sentry`, etc. — never translated.                                                                     |

If a brand noun is grammatically awkward in a target language, rephrase the surrounding sentence rather than altering the noun.

---

## 2. Legal / binding carve-out (never machine-translated)

Legally-binding content stays **English** and is never run through the generation pass:

- The master contract body and any other source under `resources/contracts/**`.
- This content lives **outside** the i18n string files specifically so the carve-out is enforceable; the S8 generation path must never touch `resources/contracts/**`.

If binding text ever needs a localized rendering, it goes through the dedicated legal track, not the MT baseline.

---

## 3. Consistent domain terms

These are real product concepts with a specific meaning. Within a single locale, pick ONE translation per term and use it everywhere (UI strings, emails, notifications). Do not vary synonyms across namespaces — consistency matters more than literary variety. `en` wording below is the source of truth.

| `en` term             | Meaning (do not drift)                                                                                                                      |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| campaign              | A brand's marketing engagement that creators are assigned to.                                                                               |
| creator               | An influencer on the platform (global entity). Do not render as "influencer", "content creator", etc. inconsistently — pick one per locale. |
| draft                 | Creator-submitted content awaiting approval (a CampaignAssignment state). Not a generic "rough version".                                    |
| roster                | An agency's managed set of creators.                                                                                                        |
| escrow                | Held funds released on completion (Stripe Connect). Use the locale's established financial term; keep it consistent.                        |
| agency                | The tenant.                                                                                                                                 |
| brand                 | An agency's client.                                                                                                                         |
| assignment            | One creator engaged on one campaign (CampaignAssignment).                                                                                   |
| board / card / column | Board-engine nouns; keep aligned with the chosen UI metaphor per locale.                                                                    |

When a target language has no established equivalent (e.g. `escrow` in some locales), prefer the locally-recognized financial/legal term over a literal calque, and apply it uniformly.

---

## 4. Placeholders, plurals, and markup

- **Interpolation placeholders** (`{named}`, `{count}`, `{list}`, `@:linked.keys`) and **HTML/markup tokens** must be preserved **byte-identical** across locales. Reorder words around a placeholder if grammar requires; never rename, translate, or drop one. This is enforced by the placeholder-integrity architecture test.
- **Plurals** must use the locale's correct CLDR plural categories (one/few/many/other, etc.). Form counts and category mapping are enforced by the plural form-count and rules-correctness specs; do not collapse a multi-form locale into two forms.
