/**
 * Admin per-field edit config — frontend mirror of the backend
 * `AdminUpdateCreatorRequest` (Sprint 3 Chunk 4 sub-step 1).
 *
 * The backend is the trust boundary. This file pre-validates and
 * shapes the modal UX (input control kind, option lists, optional
 * reason requirement, client-side max length) so admins get
 * immediate feedback without round-tripping. Backend re-validates
 * every value; any divergence here just means the PATCH 422s.
 *
 * EDITABLE_FIELDS, REASON_REQUIRED_FIELDS, max lengths, and the
 * 16-category enum are kept structurally aligned with
 * `AdminUpdateCreatorRequest::EDITABLE_FIELDS`,
 * `REASON_REQUIRED_FIELDS`, `rules()`, and `CATEGORY_ENUM`. The
 * source-inspection architecture test at
 * `apps/admin/tests/unit/architecture/field-edit-config-parity.spec.ts`
 * (sub-step 9) walks the backend file and fails CI if those
 * constants drift apart.
 *
 * Country / language option curations mirror the wizard Step 2
 * source-of-truth list at
 * `apps/main/src/modules/onboarding/pages/Step2ProfileBasicsPage.vue`.
 * The wizard list is a curated subset — the backend accepts any
 * `size:2` string, so admins may legitimately need to set values
 * outside this curation. We surface both the curated picklist AND a
 * free-text fallback for those two select fields (Q-chunk-4-3 = (b)
 * spirit — backend is SOT; frontend tolerates broader input).
 */

import type { AdminEditableField } from '../api/creators.api'

export type EditFieldControl =
  | { kind: 'text'; maxLength: number; nullable: false }
  | { kind: 'textarea'; maxLength: number; nullable: true; rows: number }
  | {
      kind: 'select'
      maxLength: number
      nullable: false
      options: ReadonlyArray<{ value: string; label: string }>
      allowCustomCode: boolean
    }
  | {
      kind: 'multi-select'
      maxItems: number | null
      minItems: number
      options: ReadonlyArray<{ value: string; label: string }>
    }
  | { kind: 'region-text'; maxLength: number; nullable: true }

export interface EditFieldConfig {
  field: AdminEditableField
  labelKey: string
  control: EditFieldControl
  reasonRequired: boolean
}

/**
 * Backend cap — `AdminUpdateCreatorRequest::rules()['display_name']`
 * uses `max:120`. The wizard Step 2 form-field counter shows 60 (a
 * UX preference, not a hard cap). We expose the backend cap here so
 * admins are not artificially constrained beyond the data model.
 */
export const DISPLAY_NAME_MAX = 120

/** Backend cap (`AdminUpdateCreatorRequest::rules()['bio']` = `max:5000`). */
export const BIO_MAX = 5000

/** Backend cap (`region` = `max:120`). */
export const REGION_MAX = 120

/** Wizard-curated country list — kept structurally aligned with
 * `apps/main/src/modules/onboarding/pages/Step2ProfileBasicsPage.vue`
 * `COUNTRY_OPTIONS`. */
export const COUNTRY_OPTIONS: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'IE', label: 'Ireland' },
  { value: 'GB', label: 'United Kingdom' },
  { value: 'PT', label: 'Portugal' },
  { value: 'IT', label: 'Italy' },
  { value: 'ES', label: 'Spain' },
  { value: 'FR', label: 'France' },
  { value: 'DE', label: 'Germany' },
  { value: 'US', label: 'United States' },
  { value: 'CA', label: 'Canada' },
]

/** Wizard-curated language list. */
export const LANGUAGE_OPTIONS: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'en', label: 'English' },
  { value: 'pt', label: 'Português' },
  { value: 'it', label: 'Italiano' },
  { value: 'es', label: 'Español' },
  { value: 'fr', label: 'Français' },
  { value: 'de', label: 'Deutsch' },
]

/**
 * 16-category enum — must stay in sync with
 * `AdminUpdateCreatorRequest::CATEGORY_ENUM` and wizard Step 2's
 * `CATEGORY_KEYS`. Architecture test in
 * `field-edit-config-parity.spec.ts` enforces parity.
 */
export const CATEGORY_KEYS: ReadonlyArray<string> = [
  'lifestyle',
  'sports',
  'beauty',
  'fashion',
  'food',
  'travel',
  'gaming',
  'tech',
  'music',
  'art',
  'fitness',
  'parenting',
  'business',
  'education',
  'comedy',
  'other',
]

export const FIELD_EDIT_CONFIG: Readonly<Record<AdminEditableField, EditFieldConfig>> = {
  display_name: {
    field: 'display_name',
    labelKey: 'admin.creators.detail.fields.display_name',
    control: { kind: 'text', maxLength: DISPLAY_NAME_MAX, nullable: false },
    reasonRequired: false,
  },
  bio: {
    field: 'bio',
    labelKey: 'admin.creators.detail.fields.bio',
    control: { kind: 'textarea', maxLength: BIO_MAX, nullable: true, rows: 6 },
    reasonRequired: true,
  },
  country_code: {
    field: 'country_code',
    labelKey: 'admin.creators.detail.fields.country_code',
    control: {
      kind: 'select',
      maxLength: 2,
      nullable: false,
      options: COUNTRY_OPTIONS,
      allowCustomCode: true,
    },
    reasonRequired: false,
  },
  region: {
    field: 'region',
    labelKey: 'admin.creators.detail.fields.region',
    control: { kind: 'region-text', maxLength: REGION_MAX, nullable: true },
    reasonRequired: false,
  },
  primary_language: {
    field: 'primary_language',
    labelKey: 'admin.creators.detail.fields.primary_language',
    control: {
      kind: 'select',
      maxLength: 2,
      nullable: false,
      options: LANGUAGE_OPTIONS,
      allowCustomCode: true,
    },
    reasonRequired: false,
  },
  secondary_languages: {
    field: 'secondary_languages',
    labelKey: 'admin.creators.detail.fields.secondary_languages',
    control: {
      kind: 'multi-select',
      maxItems: null,
      minItems: 0,
      options: LANGUAGE_OPTIONS,
    },
    reasonRequired: false,
  },
  categories: {
    field: 'categories',
    labelKey: 'admin.creators.detail.fields.categories',
    control: {
      kind: 'multi-select',
      maxItems: 8,
      minItems: 1,
      options: CATEGORY_KEYS.map((key) => ({ value: key, label: key })),
    },
    reasonRequired: true,
  },
}

/** Convenience list — same as `Object.keys(FIELD_EDIT_CONFIG)` with
 * stable ordering for iteration in tests / architecture checks. */
export const EDITABLE_FIELDS: ReadonlyArray<AdminEditableField> = [
  'display_name',
  'bio',
  'country_code',
  'region',
  'primary_language',
  'secondary_languages',
  'categories',
]

/** Mirrors `AdminUpdateCreatorRequest::REASON_REQUIRED_FIELDS`. */
export const REASON_REQUIRED_FIELDS: ReadonlyArray<AdminEditableField> = ['bio', 'categories']
