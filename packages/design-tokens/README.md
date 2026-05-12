# @catalyst/design-tokens

Design tokens for Catalyst Engine — extracted from [`docs/01-UI-UX.md`](../../docs/01-UI-UX.md).

## What's exported

- `import {...} from '@catalyst/design-tokens'` — TypeScript constants for brand colors, neutrals, status palettes, spacing, radius, and typography scales.
- `import { lightTheme, darkTheme } from '@catalyst/design-tokens/vuetify'` — both palettes as Vuetify `ThemeDefinition` objects. Plug into `createVuetify({ theme: { themes: { light, dark } } })`. The exports were renamed from `catalystLightTheme` / `catalystDarkTheme` in chunk 8.1 to align with Vuetify's standard `light` / `dark` theme keys.
- `import '@catalyst/design-tokens/tokens.css'` — global CSS custom properties for `--color-*`, `--space-*`, `--radius-*`. Components reference these, never the raw hex values.

## Conventions

- Components must reference **semantic** tokens (`--color-text-primary`, `--color-action-primary`) — never raw brand or neutral values.
- The brand gradient is reserved for logos, marketing surfaces, and onboarding splashes (not routine UI).
- Spacing follows the 4-pixel scale exclusively. If a component "needs" 28px, choose 24 or 32.

## Phase 1 status

Tokens are complete for Phase 1. The Vuetify theme adapter exposes a structural type so this package does not pull in Vuetify as a runtime dependency.

## Theme contract (chunk 8.1)

Both `lightTheme.colors` and `darkTheme.colors` cover the Vuetify-standard semantic token set:

- `background`, `on-background`, `surface`, `on-surface`
- `primary`, `on-primary`, `secondary`, `on-secondary`
- `error`, `on-error`, `success`, `on-success`, `warning`, `on-warning`, `info`, `on-info`

Plus Vuetify variant slots: `surface-bright`, `surface-light`, `surface-variant`, `on-surface-variant`, `primary-darken-1`, `border-color`, `accent`.

WCAG AA contrast on the dark palette's critical pairs is verified by `src/vuetify.spec.ts` against the WCAG 2.1 relative-luminance formula (via `colord`). The light palette's `primary`/`on-primary` pair is a known pre-existing AA failure (2.49:1) — see `docs/tech-debt.md` and the `it.todo` in the spec.
