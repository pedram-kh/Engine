# @catalyst/ui

Shared Vue 3 component library for Catalyst Engine. Components are prefixed `C`.

## Sprint 0 contents

- `CButton` — placeholder wrapper around `<v-btn>` with the Catalyst variants (`primary`, `secondary`, `ghost`, `danger`).

## Phase 1 plan

Per [`docs/02-CONVENTIONS.md`](../../docs/02-CONVENTIONS.md), Phase 1 expands this package to include:

- `CTable` (wraps `v-data-table-server` with Catalyst row density and column controls)
- `CField` (label + input + error stacking, used by every form)
- `CCard`, `CDrawer`, `CDialog`, `CTopBar`, `CSidebar`, `CBoardCard`, `CStatusBadge`, …

Components consume design tokens via the CSS variables exported by [`@catalyst/design-tokens`](../design-tokens/README.md). Never hardcode hex values inside components.
