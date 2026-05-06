# @catalyst/design-tokens

Design tokens for Catalyst Engine — extracted from [`docs/01-UI-UX.md`](../../docs/01-UI-UX.md).

## What's exported

- `import {...} from '@catalyst/design-tokens'` — TypeScript constants for brand colors, neutrals, status palettes, spacing, radius, and typography scales.
- `import {...} from '@catalyst/design-tokens/vuetify'` — `catalystLightTheme` and `catalystDarkTheme` plug into Vuetify's `createVuetify({ theme: { themes: {...} } })`.
- `import '@catalyst/design-tokens/tokens.css'` — global CSS custom properties for `--color-*`, `--space-*`, `--radius-*`. Components reference these, never the raw hex values.

## Conventions

- Components must reference **semantic** tokens (`--color-text-primary`, `--color-action-primary`) — never raw brand or neutral values.
- The brand gradient is reserved for logos, marketing surfaces, and onboarding splashes (not routine UI).
- Spacing follows the 4-pixel scale exclusively. If a component "needs" 28px, choose 24 or 32.

## Phase 1 status

Tokens are complete for Phase 1. The Vuetify theme adapter exposes a structural type so this package does not pull in Vuetify as a runtime dependency.
