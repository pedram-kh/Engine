# 01 — UI / UX Design System

> **Status: Always active reference. Defines the design language of Catalyst Engine. Cursor must apply this to every UI screen, every component, every state.**

This is the design system for Catalyst Engine. It exists so that every screen — main app, admin SPA, marketing surfaces, emails — feels like one product. It exists so that you (Cursor) don't have to make taste decisions; you apply this system.

The visual language is **ClickUp-inspired** (clean, dense, productive, slightly playful) adapted to **Catalyst Engine's** Engine C v2 brand identity: zinc true-neutral surfaces (dark by default), a teal/violet co-brand, and the aurora gradient as a thin accent. See §2 for the settled v2 color system.

---

## 1. Brand identity

### What Catalyst Engine looks and feels like

- **Confident, not loud.** The product runs serious money and serious campaigns. The UI conveys quiet competence.
- **Modern, not trendy.** No glassmorphism, no overly rounded "Big Sur" surfaces, no gradient-on-everything. Clean, crisp, geometric.
- **Productive, not decorative.** Density is high. Whitespace is intentional but not generous. Information per pixel matters.
- **Considered, not corporate.** The zinc dark-default surfaces with a restrained aurora accent give the product a distinct, human feel that pure black-on-white SaaS lacks — without leaning on decoration.

### Logo

The Catalyst logo is the geometric "C" mark with a teal-to-violet gradient square in its center, paired with the "Catalyst" wordmark in a custom geometric sans.

- Always render the logo on dark surfaces when possible — it was designed for dark.
- On light surfaces, use the dark-on-light variant (mark and wordmark in near-black `#0A0A0B`).
- Never recolor the gradient square. It is the only colored element of the logo.
- Minimum logo size: 24px height for the mark alone, 80px width for mark + wordmark.
- Clear space around logo: equal to the height of the mark.

The full product is "Catalyst Engine" but the wordmark is just "Catalyst." When written in UI, use "Catalyst Engine" for full product name, "Catalyst" as shorthand. Reserve "Catalyst" for the agency partner's branded surfaces if they ever appear.

---

## 2. Color system

Engine C v2 is a **co-brand**: the aurora accent layers _alongside_ the existing teal/violet brand identity, not replacing it. The neutral foundation is the zinc true-neutral scale; the brand primary stays teal; aurora is a thin accent only. The app is **dark-default**.

### 2.1 The neutral foundation — zinc

The surface, border, and text neutrals come from the **zinc** true-neutral scale (Tailwind zinc, 50→950). Zinc replaced the v1 warm-gray neutrals across both SPAs. The scale is defined in `packages/design-tokens/src/tokens.ts` (`zinc`) and consumed through the Vuetify theme layer — never referenced directly as hex in component code.

Dark mode (the default) maps surfaces to the dark end of the scale (background ≈ zinc-950, surface ≈ zinc-900, elevated ≈ zinc-800) and text to the light end (emphasis ≈ zinc-50, default ≈ zinc-300, muted ≈ zinc-400). Light mode inverts this. The per-mode neutral split is pinned by the `color-system-parity` architecture test (the "split neutrals" invariant: background/surface/on-surface/border differ between themes).

### 2.2 Brand primary — teal (co-brand, preserved)

The brand primary stays **teal** (`brand.teal`), unchanged from v1. The teal→violet brand gradient survives as the logo/favicon mark. Aurora does **not** replace teal as the primary; the two co-exist. Primary CTAs, links, and brand-primary surfaces use teal via the Vuetify `primary` color.

> Contrast note: teal-500 on white is ~2.49:1 (below AA-normal) — a documented characteristic of the light-mode primary (tracked as an `it.todo` in `vuetify.spec.ts`). The app's dark default avoids this regime. Light surfaces that need an AA-passing teal (e.g. the email mail theme) use a darker step (teal-700, ~5.5:1 on white). This is not an inconsistency — each surface picks the teal step that hits AA for its background.

### 2.3 The aurora accent — utility-only, thin, D7

Aurora is the v2 brand accent: the gradient `#CD69FF → #7FC3FF → #00FFF2` (magenta → sky → cyan), defined as `--brand-aurora-gradient` in `tokens.css`.

**D7 — aurora is accent-only.** It is never a primary color, never a status/semantic color, never a form field color, and **never registered in any Vuetify `theme.colors` slot**. It appears only in component CSS, consumed via `var(--brand-aurora-gradient)`. The `color-system-parity` test enforces this (the "aurora utility-only" invariant: no aurora hex — solid or gradient-string — appears in any theme's `colors`).

**Thin accents only.** Aurora is applied as a 2-3px edge-line/border, never a full-bleed fill. The gradient is high-chroma; anything larger than an accent dominates the zinc dark surface and reads as a marketing splash. The surfaced locations (as of v2):

- **Auth card** (both SPAs) — a 3px aurora top-border on the centered card. The brand moment for unauthenticated users.
- **Onboarding app-bar** — a 2px aurora line on the app-bar's bottom edge.
- **Creator dashboard** — a full-width 2px aurora header rule.

All three read as one brand language (shared gradient, 2-3px weight, full-width edge-lines). New aurora placements must hold the thin-accent + utility-only discipline; aesthetic surfaces require eyes-on verification in both modes.

### 2.4 Semantic colors — single-value, both modes

The semantic palette (success / warning / danger / info) is **single-value across both themes** (preserved from chunk 8.1, not split per-mode). The semantic-chip foregrounds use pure white (`on-info`, `on-success`) or zinc-950 (`on-warning`) — anchored to literals, not the (now-deleted) warm-neutral primitive.

> `on-info` (white on info) is ~4.07:1 — marginal, below AA-normal — a pre-existing semantic-palette characteristic, covered by the AA-Large accent-pair assertion, not a dedicated fix. It predates the brand pivot and is out of v2's scope.

### 2.5 Container / variant tokens

Four Material container/variant tokens are explicitly registered in both themes (rather than relying on Vuetify auto-derivation or undefined-token fallbacks): `outline` (zinc-300/700), `outline-variant` (zinc-200/800), `primary-container` (teal-50 / teal-800), `error-container` (danger-100 / #3B1A1A). Registering them explicitly fixed a latent bug where the unregistered `outline` token left dropzone borders not rendering. Pinned by the `color-system-parity` test (the container-token invariant).

### 2.6 The theme model — binary, dark-default

Engine C v2 is **binary dark-first**:

- Two themes only: `dark` (default) and `light`. There is no `'system'` / OS-preference option — it was removed, and `matchMedia(prefers-color-scheme)` detection is ratcheted shut (a `use-theme-is-sot` architecture test forbids reintroducing it).
- First visit renders **dark** regardless of OS preference.
- The Vuetify theme keys are `light` / `dark` (Vuetify-standard naming, per chunk 8.1). The brand identity lives in the theme _values_, not the key names.
- The theme toggle is binary (two states); preference persists in `localStorage`.

### 2.7 How to consume color — the correct path

**Use the Vuetify theme layer, not raw hex and not the (deleted) `--color-*` vars.**

- In Vue components, prefer Vuetify color props: `color="primary"`, `color="surface"`, `color="error"`, etc. These resolve through the registered theme.
- In component `<style>`, use the Vuetify-generated CSS vars: `rgb(var(--v-theme-surface))`, `rgb(var(--v-theme-on-surface))`, `rgb(var(--v-theme-primary))`, `rgb(var(--v-theme-outline))`, etc.
- For the aurora accent only: `var(--brand-aurora-gradient)`.
- **Never** raw hex in component CSS (the `no-hard-coded-colors` test forbids it). **Never** the old `--color-*` / `--neutral-*` vars — they were dormant and are now deleted; the real path is the Vuetify theme layer above.

The Vuetify theme is the single source of truth for color. The design-tokens TS layer feeds the theme; `tokens.css` carries only `--brand-*` (incl. aurora), `--radius-*`, `--space-*`, and `--catalyst-typography-*` — not a parallel color system.

---

## 3. Typography

### Typeface

- **Primary UI font:** **Inter** — self-hosted as **static weights 400 / 500 / 600 / 700, normal + italic** (latin subset). Italic ships because the creator-bio renderer emits `<em>` / `<strong><em>`. (An interim build shipped the variable font; it was reverted to static weights before commit per the Chunk 1 locked decision. Variable Inter is a deliberate future cleanup if ever revisited, not a silent swap.)
- **Monospace:** **JetBrains Mono** (for codes, IDs, technical values).
- **Self-host location:** the fonts + `inter.css` live in the shared **`packages/ui/assets/fonts/`** package and are imported once from each SPA's `main.ts` (not per-SPA `public/fonts/`). The `.v-application { font-family: var(--brand-font-primary) }` cascade override in `inter.css` applies the typeface app-wide. No Google Fonts / CDN runtime dependency (GDPR-friendliness, performance).
- **`font-feature-settings`:** not currently applied. The v1 spec called for `"cv02", "cv03", "cv04", "cv11"` (humane glyph variants); the as-shipped self-hosted static weights do not enable the stylistic sets. Enabling them is an optional future typography refinement, not a shipped characteristic — documented here so the doc matches the code.

### Type scale

A modular scale based on 16px base, ratio 1.2.

```
display-xl   48px / 56px line-height / 700 weight / -0.02em letter-spacing
display      36px / 44px / 700 / -0.02em
heading-1    28px / 36px / 600 / -0.01em
heading-2    22px / 30px / 600 / -0.005em
heading-3    18px / 26px / 600 / 0
heading-4    16px / 24px / 600 / 0
body-lg      16px / 24px / 400 / 0
body         14px / 22px / 400 / 0      ← default body text
body-sm      13px / 20px / 400 / 0
caption      12px / 18px / 500 / 0.01em
overline     11px / 16px / 600 / 0.08em / uppercase
mono         13px / 20px / 500 / 0      ← JetBrains Mono
```

### Rules

- Body text is **14px** default. This is denser than typical 16px-default sites — it matches ClickUp's productive density.
- Never use display sizes inside the application UI. Display sizes are for marketing surfaces and onboarding hero moments only.
- Never use more than three type levels on a single screen. Hierarchy by weight and color is preferred over hierarchy by size.
- Never center body text. Always left-align (or right-align in RTL — but Phase 1 has no RTL).
- Truncate long text with ellipsis (`text-overflow: ellipsis`). Tooltips reveal the full content on hover.

---

## 4. Spacing & layout

### Spacing scale

Based on 4px units. **Use only these values.**

```
space-0    0px
space-1    4px
space-2    8px
space-3    12px
space-4    16px
space-5    20px
space-6    24px
space-8    32px
space-10   40px
space-12   48px
space-16   64px
space-20   80px
space-24   96px
```

Do not invent new values. If you need 28px, you don't — pick 24 or 32.

### Component density

ClickUp is dense. Catalyst Engine is dense. Defaults:

- **Form inputs:** 36px tall (Vuetify `density="compact"`).
- **Buttons:** 32px tall by default; 28px for `size="small"`; 40px for `size="large"`.
- **Table rows:** 40px tall, 12px vertical padding.
- **Sidebar items:** 32px tall.
- **Tabs:** 36px tall.

Spacious mode (used only on creator-facing onboarding screens for warmth) bumps these up by ~4px.

### Layout grid

- **Breakpoints:** Vuetify defaults — `xs<600`, `sm<960`, `md<1280`, `lg<1920`, `xl≥1920`.
- **Main app layout:**
  - Top bar: 56px tall, full width
  - Left sidebar: 240px wide on `lg+`, collapsible to 64px (icons only) on `md`, hidden behind drawer on `sm`
  - Main content: fluid, max readable width 1440px, with side gutters
- **Admin SPA layout:**
  - Top bar: 48px tall (denser, less brand)
  - Left sidebar: 220px, never collapsed
  - Main content: fluid, no max width (admins want every pixel)
- **Modal max-width:** 640px default, 880px for "wide" content, 1120px for "extra wide" (rare).
- **Side drawer (right side):** 480px default for entity detail, 640px for editing flows.

---

## 5. Component patterns (ClickUp-inspired)

These are the building blocks. Cursor implements every screen using these patterns; do not invent alternatives.

### Buttons

**Primary** — solid teal, used for the single most important action per screen. Never two primary buttons on one screen.

**Secondary** — neutral background, dark text. Used for non-primary actions.

**Ghost** — transparent, dark text, subtle hover. Used for tertiary actions inside dense UIs (table rows, card actions).

**Danger** — solid red, used only for destructive confirmations.

Buttons are **rectangular with 6px border-radius**. Not pill-shaped. Not square. 6px.

Icon-only buttons are 32x32, square, 6px border-radius. Tooltip on hover is mandatory.

### Inputs

- **Text inputs:** 36px tall, 1px border (`rgb(var(--v-theme-outline))`), 6px radius. On focus: 2px ring in the `primary` color, no border color change. Subtle label above the input, never floating-label inside the input.
- **Select / dropdowns:** Same shape as text inputs. Open downward by default. Search-as-you-type for any list with more than 8 items.
- **Date pickers:** Calendar in a popover. Keyboard navigable. Defaults to today's date highlighted. Start of week respects locale (Monday for EU).
- **Checkboxes:** 16x16, 4px radius. Teal when checked.
- **Switches:** Toggle pattern, 36x20, with text label to the left.

### Tables

The most important component in the agency-side experience. Tables are how agencies see their world.

- **Header row:** sticky, sunken-surface background (`rgb(var(--v-theme-surface))` family), 12px caption-style text in the secondary tone (`rgb(var(--v-theme-on-surface-variant))`).
- **Body rows:** 40px tall, alternating row backgrounds NOT used (it adds visual noise). Bottom border 1px in `rgb(var(--v-theme-outline-variant))`.
- **Row hover:** background shifts to the sunken surface tone. Cursor changes to pointer if the row is clickable.
- **Selected row:** subtle teal-tinted background via the registered `primary-container` token (`rgb(var(--v-theme-primary-container))` — teal-50 light / teal-800 dark).
- **Checkbox column:** 40px wide, leftmost. For bulk operations.
- **Column resize:** enabled by default. Persisted per user.
- **Column reorder:** enabled.
- **Column sort:** click header. Three states: asc, desc, default.
- **Column filter:** hover header, filter icon appears. Clicking opens a popover.
- **Empty state:** centered, with an illustration or icon, a brief message, and a primary action.
- **Pagination:** server-side. 25/50/100 per page. Shows "X–Y of Z" current range.

The table component is `<CTable>` in `packages/ui/`. Cursor uses this component, not Vuetify's `<v-data-table>` directly. Internally, `<CTable>` wraps `<v-data-table-server>` with our styling.

### Cards

A card is a contained piece of information with a colored top accent (often a status color).

- **Padding:** 16px.
- **Border:** 1px `rgb(var(--v-theme-outline-variant))`.
- **Radius:** 8px.
- **Shadow:** none by default. On hover, subtle shadow.
- **Status accent:** an optional 3px tall colored bar at the very top of the card (matching board status colors).
- **Card title:** heading-4 (16px / 600).
- **Card body:** body (14px / 400).

The board card variant is more specialized — see `10-BOARD-AUTOMATION.md` and the board card pattern below.

### Board cards (campaign board specifically)

Board cards represent CampaignAssignments (one creator on one campaign). They look like ClickUp's task cards.

- **Width:** fills column (typically 280–320px).
- **Padding:** 12px.
- **Top region:** creator avatar (24x24) + creator handle.
- **Title region:** assignment summary ("1 Reel + 3 Stories") in body-sm.
- **Bottom row:** small icons for: platform (IG/TikTok/YT), days remaining (red if overdue), unread message count, status badge.
- **Drag handle:** the entire card is draggable. Visual cue on hover.
- **Click:** opens the assignment side panel (480px right drawer).

### Sidebar (left navigation)

- **Top section:** Catalyst Engine logo (mark + wordmark on `lg+`, just mark on collapsed).
- **Workspace switcher:** below logo, shows current agency (and brand context if selected). Dropdown to switch. Pattern matches ClickUp's workspace switcher.
- **Primary nav items:** icon + label. Active item has a teal left-border accent and a sunken-surface background (`rgb(var(--v-theme-surface))` family).
- **Sections:** "Workspace", "Brands" (lists each brand), "Reports", "Admin".
- **Bottom section:** user avatar, settings, theme toggle.

### Top bar

- **Left:** if sidebar is collapsed/hidden, hamburger to expand.
- **Center:** global search (cmd+K). Important component — see below.
- **Right:** notifications bell, user menu, agent/AI button (Phase 3+).

### Global search (cmd+K)

A modal that opens on `Cmd+K` / `Ctrl+K`. Search across creators, campaigns, brands, messages. Keyboard-driven. Recent searches shown by default.

### Status badges

Pill-shaped, 4px radius (slightly less round than badges in some products), 11px caption text, semibold, with a 6px colored dot at left.

```
○ TO DEFINE       gray dashed
● IN PROGRESS     violet
● IN REVIEW       amber
● ALIGNED         teal
● POSTED          cyan
● PAID            green
● BLOCKED         red
```

Badge color uses the appropriate `status-*` token. Background is the badge color at 12% opacity (light theme) or 24% opacity (dark theme).

### Empty states

Every list, every table, every board column should have a designed empty state. Not just "No data."

Pattern: a centered icon (or simple illustration), a one-line headline (`heading-3`), a one-sentence helper (`body` in the secondary tone, `rgb(var(--v-theme-on-surface-variant))`), and a primary action button.

Examples:

- Empty creator roster: "No creators yet — invite your roster to get started."
- Empty campaign list for a brand: "No campaigns yet — create the first one."
- Empty messages: "No messages — say hello to your creator."

### Modals & drawers

- **Modals** are for focused, blocking decisions ("delete this brand?"). Centered. Backdrop dimmed.
- **Side drawers** (right side) are for editing or detailed views without losing context (open a campaign assignment to review and approve a draft, while the board stays visible behind). Drawers slide in from the right.
- **Don't use modals for editing flows.** Use drawers. Modals are for decisions, drawers are for work.

### Toasts (notifications)

- Bottom-right corner.
- Auto-dismiss after 4 seconds (success/info), 8 seconds (warning), manual dismiss for errors.
- Stacked, max 3 visible.
- Each has an icon, a message, and an optional action ("Undo").

### Loading states

- **Skeleton loaders** for tables, cards, profile views — not spinners. Skeletons match the shape of the content they're replacing.
- **Inline spinners** are 14px and used inside buttons during submission.
- **Page-level loaders** are reserved for very rare cases (full app boot). Don't use them between routes.

---

## 6. Iconography

- **Library:** Tabler Icons (free, comprehensive, geometric, matches the Inter+ClickUp aesthetic).
- **Default size:** 16px in dense UI, 20px for prominent buttons, 24px for navigation.
- **Stroke width:** 1.5 (Tabler's default).
- **Color:** inherits text color via `currentColor`. Never hardcode icon color outside semantic tokens.
- **Don't mix icon libraries.** All icons come from Tabler. If Tabler lacks an icon, do without it or commission one (rare).

Vuetify is configured to use Tabler Icons via the `mdi` aliases replaced with Tabler equivalents.

---

## 7. Motion & animation

- **Default duration:** 150ms for state changes (hover, active, focus).
- **Drawer / modal transitions:** 200ms ease-out.
- **Sidebar collapse:** 250ms ease-in-out.
- **Toast enter:** 200ms ease-out, 150ms ease-in for exit.
- **Card drag:** physics-based via the drag library; not custom-timed.
- **Page transitions:** none. Routes change instantly. No fades.
- **`prefers-reduced-motion`:** respect it. Disable non-essential animations.

Motion is functional, not decorative. Bouncing icons, animated shimmers, and hero animations are not in this design system.

---

## 8. Imagery & illustration

Phase 1 has no custom illustrations. Empty states use Tabler icons at large size with a soft background circle (the sunken surface tone, `rgb(var(--v-theme-surface))` family) for visual weight.

Phase 2+ may introduce custom illustrations matching the geometric, slightly hand-cut feel of the logo. When that happens, this section will be updated.

---

## 9. Accessibility

Non-negotiable. Cursor implements every component to meet WCAG 2.1 AA.

- **Color contrast:** all text meets 4.5:1 contrast for body text, 3:1 for large text. Verified per token combination.
- **Keyboard navigation:** every interactive element is reachable by keyboard. Tab order is logical.
- **Focus indicators:** visible 2px ring on all focusable elements. Never `outline: none` without replacement.
- **ARIA:** semantic HTML first; ARIA only when needed.
- **Screen reader labels:** every icon-only button has an `aria-label`. Every form input has an associated `<label>`.
- **Form errors:** announced via `aria-live` regions.
- **Color is never the only signal.** Status badges have icons or shapes in addition to color.
- **Skip-to-content link** at the top of every page.

---

## 10. Vuetify configuration

Vuetify 3 is the underlying component library, but it is heavily themed and selectively used.

### Theming

- Vuetify theme config lives in `apps/main/src/plugins/vuetify.ts` (and identically in admin) and reads from `packages/design-tokens/`.
- Both `light` and `dark` themes are defined. The app is **dark by default** (binary toggle, no OS-preference/system option — see §2.6); the user's explicit choice persists in `localStorage`.
- Vuetify's default color names (`primary`, `secondary`, `success`, etc.) are aliased to Catalyst Engine's semantic tokens.

### Components used directly

- `v-btn`, `v-text-field`, `v-select`, `v-checkbox`, `v-switch`, `v-tooltip`, `v-menu`, `v-dialog` (modals), `v-navigation-drawer` (drawers), `v-app-bar`, `v-list`, `v-tabs`.

### Components wrapped in `packages/ui/`

These get a Catalyst Engine-specific wrapper because their default Vuetify behavior or styling needs adjustment:

- `<CTable>` wraps `v-data-table-server`
- `<CButton>` wraps `v-btn` with our variant set
- `<CCard>` wraps `v-card` with our padding/border defaults
- `<CStatusBadge>` is fully custom (no Vuetify equivalent)
- `<CBoardCard>` is fully custom
- `<CEmptyState>` is fully custom
- `<CDrawer>` wraps `v-navigation-drawer` for right-side detail drawers
- `<CSidebar>` is fully custom
- `<CTopBar>` is fully custom
- `<CSearchPalette>` is fully custom (the cmd+K modal)

Cursor uses the wrapper components, not the underlying Vuetify components, anywhere a wrapper exists.

### Components to avoid

- `v-card` directly — use `<CCard>`.
- `v-data-table` (non-server) — always paginate server-side.
- `v-snackbar` directly — use the toast composable `useToast()`.
- `v-banner` — not part of our design language.
- Vuetify's default colors like `primary` referenced raw — go through the token system.

---

## 11. Per-screen patterns

### Sign-in / sign-up

- Centered card on a full-bleed dark background.
- Logo at top.
- Form inside the card, max 360px wide.
- Below the form: switch between sign-in / sign-up / reset.

### Onboarding (creator-facing)

- Multi-step wizard with progress indicator at top.
- Each step is a single focused task.
- "Save and continue later" available from step 2 onward.
- Steps explicitly labeled; user always knows where they are.

### Workspace home (agency-facing)

- Welcome bar with user name and current date.
- Top: KPI strip (active campaigns, creators in roster, pending approvals, payments due) — small cards with the `caption` label and `heading-2` value.
- Below: two-column layout — recent activity on left, upcoming deadlines on right.
- Floating action button (bottom-right): "New campaign" — primary teal.

### Campaign detail (agency-facing)

- Top: campaign title, brand context, key dates.
- Tab strip: Overview · Board · Creators · Drafts · Payments · Messages · Settings.
- Default tab is Board for active campaigns.
- Right side reserved for the assignment detail drawer when opened.

### Board view

See `10-BOARD-AUTOMATION.md` for full spec. Visual key points:

- Column header is sticky with status badge and count.
- "+ Add task" affordance at the bottom of each column.
- Board scrolls horizontally on overflow; columns don't shrink below 280px.
- Drag preview is the card with reduced opacity and a subtle shadow.

### Admin SPA — different visual register

The admin SPA is more utilitarian than the main app. Same components, same tokens, but:

- Less brand presence (no gradient, smaller logo).
- Denser tables (32px row height instead of 40px).
- More information per screen.
- Admin-specific status colors are slightly different (red is heavier, used for production-critical alerts).

Admins live in this UI all day. It's optimized for power, not warmth.

---

## 12. Dos and Don'ts (Cursor reference)

### Do

- Consume color through the Vuetify theme layer (§2.7): `color="primary"` / `color="surface"` props, and `rgb(var(--v-theme-*))` in `<style>`. The aurora accent only via `var(--brand-aurora-gradient)`. Never raw hex, and never the deleted `--color-*` / `--neutral-*` vars.
- Use the spacing scale, never arbitrary pixel values.
- Use the type scale, never arbitrary font sizes.
- Use `<CTable>`, `<CButton>`, etc. when wrappers exist.
- Test every component in both light and dark mode.
- Test keyboard navigation on every interactive surface.
- Add an empty state to every list, every table, every board column.
- Add a loading skeleton to every async surface.
- Use the toast composable for non-blocking feedback.

### Don't

- Don't use raw Vuetify defaults without our customization.
- Don't introduce new font families. Inter and JetBrains Mono only.
- Don't introduce new colors outside this system.
- Don't use animations longer than 250ms.
- Don't use emoji in UI text. Reserved for user-generated content only.
- Don't center-align body text.
- Don't use `position: fixed` for content that should scroll with the page.
- Don't disable focus indicators.
- Don't ship a screen without empty, loading, and error states.

---

## 13. Component library structure

```
packages/ui/
├── components/
│   ├── CButton/
│   │   ├── CButton.vue
│   │   ├── CButton.spec.ts
│   │   └── index.ts
│   ├── CTable/
│   ├── CCard/
│   ├── CStatusBadge/
│   ├── CBoardCard/
│   ├── CEmptyState/
│   ├── CDrawer/
│   ├── CSidebar/
│   ├── CTopBar/
│   ├── CSearchPalette/
│   └── ...
├── composables/
│   ├── useToast.ts
│   ├── useTheme.ts
│   ├── useBreakpoint.ts
│   └── ...
├── tokens/
│   └── (re-export from design-tokens package)
└── index.ts
```

Each component:

- Has its own folder
- Has a `.vue` file with `<script setup lang="ts">`
- Has a `.spec.ts` Vitest file
- Has an `index.ts` re-exporting the component
- Is documented in a top-level `README.md` for the package (Phase 2)

Storybook is **not in scope for Phase 1**. It comes in Phase 2 if useful.

---

## 14. Quick reference card (post this above your desk)

```
Neutrals:        zinc scale (true-neutral, 50→950) — dark-default surfaces
Brand primary:   teal #14B8A6 (co-brand; violet #8B5CF6 in the logo gradient)
Aurora accent:   #CD69FF→#7FC3FF→#00FFF2 — utility-only, thin (2-3px), never a theme color
Consume color:   Vuetify color props + var(--v-theme-*); aurora via var(--brand-aurora-gradient)
Default font:    Inter (self-hosted static 400/500/600/700 +italic), body 14px / 22px, 400
Spacing unit:    4px (use only the scale)
Border radius:   6px buttons, 8px cards, 4px badges
Row height:      40px main app, 32px admin, 36px form inputs
Density:         compact / dense, ClickUp-inspired
Primary CTA:     solid teal, 32px tall, never two per screen
Tables:          server-side, 25/50/100 pages, sticky header, hover row, no zebra
Status:          dashed gray, violet, amber, teal, cyan, green, red
Modals vs drawers: modals for decisions, drawers for editing
Toasts:          bottom-right, max 3 stacked
Icons:           Tabler, 16px / 20px / 24px, currentColor
Motion:          150ms hover, 200ms drawer, no page transitions
Themes:          light + dark, dark-default (binary, no OS-preference), user-overrideable
Languages:       en, pt, it from day one
A11y:            WCAG 2.1 AA, keyboard nav, focus rings, ARIA labels
```

---

**End of design system. Apply it to every pixel.**
