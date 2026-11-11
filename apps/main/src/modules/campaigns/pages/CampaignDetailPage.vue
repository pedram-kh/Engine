<script setup lang="ts">
/**
 * Campaign detail page (Sprint 8 Chunk 1) — the app's FIRST tabbed
 * (v-tabs + v-window) surface.
 *
 *   - Overview  — campaign summary (live).
 *   - Creators  — the assignment list (live, read-only; empty until Chunk 2
 *                 wires inviting).
 *   - Settings  — config edit (live; admin/manager only — `canEdit`).
 *   - Board / Drafts / Applications / Messages — live tabs (lazy-mounted where
 *                 noted).
 *   - Payments — empty-state "coming soon".
 */

import {
  COUNTRY_OPTIONS,
  formatCurrency,
  formatDate,
  ApiError,
  extractFieldErrors,
  type CampaignAssignmentResource,
  type CampaignResource,
  type BrandOptionResource,
  type CampaignStatus,
  type CreateCampaignPayload,
  type UpdateCampaignPayload,
} from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'

import { CEmptyState } from '@catalyst/ui'

import CreatorProfileDialog from '@/components/CreatorProfileDialog.vue'
import RichBrief from '@/components/RichBrief.vue'
import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import AgencyPageHeader from '@/modules/agency/components/AgencyPageHeader.vue'
import AgencyStatusPill from '@/modules/agency/components/AgencyStatusPill.vue'
import { brandsApi } from '@/modules/brands/api/brands.api'
import { campaignsApi } from '../api/campaigns.api'
import CampaignForm from '../components/CampaignForm.vue'
import { campaignTabFromQuery, DEFAULT_CAMPAIGN_TAB } from '../campaignTabs'
import { LISTING_FLOOR_FIELDS, missingListingFloorFields } from '../listingFloor'
import InviteCreatorsDialog from '../components/InviteCreatorsDialog.vue'
import AttachContractDialog from '../components/AttachContractDialog.vue'
import EditOfferDialog from '../components/EditOfferDialog.vue'
import ReinviteDialog from '../components/ReinviteDialog.vue'
import ReviewDraftDrawer from '../components/ReviewDraftDrawer.vue'
import ResolveVerificationDrawer from '../components/ResolveVerificationDrawer.vue'
import ViewPostedContentDrawer from '../components/ViewPostedContentDrawer.vue'
import CampaignMessagesPanel from '@/modules/messaging/components/CampaignMessagesPanel.vue'
import BoardView from '@/modules/boards/components/BoardView.vue'
import DraftsTab from '../components/DraftsTab.vue'
import ApplicationsTab from '../components/ApplicationsTab.vue'
import AttachmentsTab from '../components/AttachmentsTab.vue'
import CampaignFilesStrip from '../components/CampaignFilesStrip.vue'
import { useCampaignFiles } from '../composables/useCampaignFiles'
import {
  creatorAvatarAlt,
  creatorAvatarUrl,
  creatorInitial,
  creatorLabel,
} from '@/core/creators/creatorLabel'

const { t, locale } = useI18n()
const route = useRoute()
const agencyStore = useAgencyStore()
const authStore = useAuthStore()
// AH-118: `/me` feature switches (agency side). UI gates only; the API enforces both.
const regionRestrictionEnabled = computed(
  () => authStore.user?.attributes.features?.campaign_region_restriction === true,
)
// AH-120: brand exclusivity (agency side). UI gate only; the API enforces it.
const brandExclusivityEnabled = computed(
  () => authStore.user?.attributes.features?.campaign_brand_exclusivity === true,
)
/** The agency's active brands, for the Settings multi-select (only fetched while the flag is on). */
const exclusivityBrandOptions = ref<{ id: string; name: string }[]>([])
async function loadExclusivityBrands(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || !brandExclusivityEnabled.value) return
  try {
    const res = await brandsApi.listOptions(agencyId, 'active')
    exclusivityBrandOptions.value = res.data.map((b: BrandOptionResource) => ({
      id: b.id,
      name: b.attributes.name,
    }))
  } catch {
    exclusivityBrandOptions.value = []
  }
}
const offerEditEnabled = computed(
  () => authStore.user?.attributes.features?.assignment_offer_edit === true,
)
const { currentRole } = storeToRefs(agencyStore)

const canEdit = computed(
  () => currentRole.value === 'agency_admin' || currentRole.value === 'agency_manager',
)

// The execute ability (D-6) — inviting is broader than editing: admin +
// manager + STAFF can invite creators to a campaign.
const canInvite = computed(
  () =>
    currentRole.value === 'agency_admin' ||
    currentRole.value === 'agency_manager' ||
    currentRole.value === 'agency_staff',
)

const canAttachContract = canInvite

const inviteDialog = ref(false)
const inviteSnackbar = ref<string | null>(null)

function onInvited(message: string): void {
  inviteSnackbar.value = message
  void loadAssignments()
}

// AH-080 (D2a) — the Creators-tab row opens the creator profile dialog.
// Always assumeFull: false — an assignment implies a relation TODAY, but the
// dialog's own fallback is the honest guard against an edge case (e.g. a
// disconnect between invite and view), not a claim this context is thin-only.
const profileDialog = ref(false)
const profileTarget = ref<CampaignAssignmentResource | null>(null)

function openProfile(assignment: CampaignAssignmentResource): void {
  if (assignment.attributes.creator === null) return
  profileTarget.value = assignment
  profileDialog.value = true
}

const reinviteDialog = ref(false)
const reinviteTarget = ref<CampaignAssignmentResource | null>(null)
const reinviteSnackbar = ref<string | null>(null)

function openReinvite(assignment: CampaignAssignmentResource): void {
  reinviteTarget.value = assignment
  reinviteDialog.value = true
}

function onReinvited(): void {
  reinviteSnackbar.value = t('app.campaigns.reinvite.success')
  void loadAssignments()
}

// AH-118 — edit the offer amount. The entry point is hidden while the flag is
// off, and also when the stage itself ends editing (`status` lock): there is
// nothing the agency can do there (a counter has Re-invite). The contract and
// payment locks stay visible so the agency learns WHY, in the dialog.
const editOfferDialog = ref(false)
const editOfferTarget = ref<CampaignAssignmentResource | null>(null)
const editOfferSnackbar = ref<string | null>(null)

function canShowEditOffer(a: CampaignAssignmentResource): boolean {
  return (
    offerEditEnabled.value &&
    canInvite.value &&
    a.attributes.offer_edit_lock_reason !== 'status' &&
    a.attributes.offer_edit_lock_reason !== 'feature_disabled'
  )
}

function openEditOffer(assignment: CampaignAssignmentResource): void {
  editOfferTarget.value = assignment
  editOfferDialog.value = true
}

function onOfferEdited(): void {
  editOfferSnackbar.value = t('app.campaigns.offerEdit.success')
  void loadAssignments()
}

const attachContractDialog = ref(false)
const attachContractTarget = ref<CampaignAssignmentResource | null>(null)
const attachContractSnackbar = ref<string | null>(null)
const attachContractError = ref<string | null>(null)

function openAttachContract(assignment: CampaignAssignmentResource): void {
  attachContractTarget.value = assignment
  attachContractDialog.value = true
}

function onContractAttached(): void {
  attachContractSnackbar.value = t('app.campaigns.contract.attach.success')
  void loadAssignments()
}

function onContractAttachError(message: string): void {
  attachContractError.value = message
}

// The per-campaign manual-contract flag (D-7), read from the assignments
// list meta. Gates the agency "proceed without a contract" action together
// with the campaign's `requires_per_campaign_contract` (false → optional).
const perCampaignContractEnabled = ref(false)
const proceedSnackbar = ref<string | null>(null)
const proceedError = ref<string | null>(null)

function canProceedWithoutContract(assignment: CampaignAssignmentResource): boolean {
  return (
    assignment.attributes.status === 'accepted' &&
    assignment.attributes.has_pending_contract !== true &&
    canAttachContract.value &&
    perCampaignContractEnabled.value &&
    campaign.value?.attributes.requires_per_campaign_contract === false
  )
}

async function proceedWithoutContract(assignment: CampaignAssignmentResource): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return
  try {
    await campaignsApi.proceedWithoutContract(agencyId, ulid.value, assignment.id)
    proceedSnackbar.value = t('app.campaigns.contract.proceedWithout.success')
    void loadAssignments()
  } catch (err) {
    proceedError.value =
      err instanceof ApiError && err.code === 'assignment.per_campaign_contract_required'
        ? t('app.campaigns.contract.proceedWithout.requiredError')
        : t('app.campaigns.contract.proceedWithout.error')
  }
}

// The draft-review surface (Sprint 9 Chunk 2, D-8) — the `review` ability is
// the execute ability: admin + manager + staff (mirrors canInvite).
const canReview = canInvite
const reviewDialog = ref(false)
const reviewTarget = ref<CampaignAssignmentResource | null>(null)
const reviewSnackbar = ref<string | null>(null)

function openReview(assignment: CampaignAssignmentResource): void {
  reviewTarget.value = assignment
  reviewDialog.value = true
}

function onReviewed(message: string): void {
  reviewSnackbar.value = message
  void loadAssignments()
  if (tab.value === 'drafts') {
    void draftsTabRef.value?.reload()
  }
}

const draftsTabRef = ref<InstanceType<typeof DraftsTab> | null>(null)

// The Applications tab (Jobs Board chunk 4, D1). The badge counts PENDING rows
// only, hoisted from the list's own scoped count — never the creator-facing
// `applicant_count`, which is interest semantics and would never clear.
const applicationsTabRef = ref<InstanceType<typeof ApplicationsTab> | null>(null)
const pendingApplications = ref(0)
// The count is drawn in "At a glance" only once it is a real number: a failed read is not a zero.
const pendingKnown = ref(false)
const applicationsSnackbar = ref<string | null>(null)

function onApplicationAnswered(message: string): void {
  applicationsSnackbar.value = message
  // An accepted application creates an `invited` assignment, so the Creators tab
  // and the board both have new truth to show.
  void loadAssignments()
}

// The verification-failure resolution surface (verification-resolution chunk,
// D-7). Same `review` ability as the draft review. The row action shows only
// when the assignment is `posted` AND its latest verification FAILED.
const resolveDialog = ref(false)
const resolveTarget = ref<CampaignAssignmentResource | null>(null)

function canResolveVerification(a: CampaignAssignmentResource): boolean {
  return (
    canReview.value &&
    a.attributes.status === 'posted' &&
    (a.attributes.verification_status === 'not_found' ||
      a.attributes.verification_status === 'mismatch')
  )
}

function openResolve(assignment: CampaignAssignmentResource): void {
  resolveTarget.value = assignment
  resolveDialog.value = true
}

const viewPostDialog = ref(false)
const viewPostTarget = ref<CampaignAssignmentResource | null>(null)

// Read-only "view posted content" — offered on any row that already has a
// post (`verification_status !== null`), EXCEPT when the failure-resolution
// action is offered instead (that drawer shows the post + the actions).
function canViewPost(a: CampaignAssignmentResource): boolean {
  return canReview.value && a.attributes.verification_status !== null && !canResolveVerification(a)
}

function openViewPost(assignment: CampaignAssignmentResource): void {
  viewPostTarget.value = assignment
  viewPostDialog.value = true
}

function onResolved(message: string): void {
  reviewSnackbar.value = message
  void loadAssignments()
  if (tab.value === 'drafts') {
    void draftsTabRef.value?.reload()
  }
}

const ulid = computed(() => String(route.params.ulid))
// AH-103: a deep link may name the tab (`?tab=drafts`); anything off the whitelist
// opens the default. A second link to the SAME campaign changes only the query, so
// the page watches it instead of assuming a fresh mount.
const tab = ref<string>(campaignTabFromQuery(route.query.tab) ?? DEFAULT_CAMPAIGN_TAB)

/**
 * The header's "Invite creators" (AH-114 S3.2): the invite dialog lives with the Creators tab
 * (it is mounted there, and a successful invite refreshes that list), so the button takes you
 * to that tab and opens the dialog on it.
 */
function openInvite(): void {
  tab.value = 'creators'
  inviteDialog.value = true
}

watch(
  () => route.query.tab,
  (value) => {
    const next = campaignTabFromQuery(value)
    if (next !== null) {
      tab.value = next
    }
  },
)

const campaign = ref<CampaignResource | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const assignments = ref<CampaignAssignmentResource[]>([])
const assignmentsLoading = ref(false)

// Settings edit state.
const editForm = ref<CreateCampaignPayload | null>(null)
const editStatus = ref<CampaignStatus>('draft')
const saving = ref(false)
const saveError = ref<string | null>(null)
const saveSuccess = ref(false)
const fieldErrors = ref<Partial<Record<string, readonly string[]>>>({})

const statusOptions: { title: string; value: CampaignStatus }[] = [
  { title: t('app.campaigns.status.draft'), value: 'draft' },
  { title: t('app.campaigns.status.active'), value: 'active' },
  { title: t('app.campaigns.status.paused'), value: 'paused' },
  { title: t('app.campaigns.status.completed'), value: 'completed' },
  { title: t('app.campaigns.status.cancelled'), value: 'cancelled' },
]

// ── Jobs board (AH-054, D3/D4/D5) ───────────────────────────────────────────
// The toggle is Settings-only and mirrors two backend rules so the user never
// meets a 422 first: it needs the listing floor filled, and it cannot be
// switched ON once the campaign is completed or cancelled.
const editListed = ref(false)

const listingFloorMissing = computed(() =>
  editForm.value === null
    ? LISTING_FLOOR_FIELDS.slice()
    : missingListingFloorFields(editForm.value),
)

const listedFieldErrors = computed<string[]>(
  () => (fieldErrors.value.listed_on_jobs_board as string[] | undefined) ?? [],
)

const listingStatusBlocked = computed(
  () => editStatus.value === 'completed' || editStatus.value === 'cancelled',
)

/** Already-listed campaigns keep their switch usable — turning it OFF never gates. */
const listedAtLoad = computed(() => campaign.value?.attributes.listed_on_jobs_board === true)

const listingToggleDisabled = computed(() => {
  if (editListed.value) return false // switching off is always allowed
  if (listingStatusBlocked.value && !listedAtLoad.value) return true
  return listingFloorMissing.value.length > 0
})

const listingToggleHint = computed(() => {
  if (listingStatusBlocked.value && !listedAtLoad.value && !editListed.value) {
    return t('app.campaigns.listing.terminalHint')
  }
  if (!editListed.value && listingFloorMissing.value.length > 0) {
    return t('app.campaigns.listing.incompleteHint', {
      fields: listingFloorMissing.value
        .map((f) => t(`app.campaigns.listing.floorFields.${f}`))
        .join(', '),
    })
  }
  return t('app.campaigns.listing.toggleHint')
})

const comingSoonTabs = ['payments'] as const

function toDateInput(iso: string | null): string | undefined {
  return iso ? iso.slice(0, 10) : undefined
}

function seedEditForm(c: CampaignResource): void {
  // `objective`, `target_creator_count`, and `brief` are intentionally NOT
  // seeded: the simplified form omits them on save, so the backend `sometimes`
  // rules preserve their stored values by omission (D-1/D-2/D-3). Re-seeding
  // them here would re-send them and revive the brief overwrite path.
  editForm.value = {
    brand_id: c.relationships.brand.data.id,
    name: c.attributes.name,
    budget_minor_units: c.attributes.budget_minor_units ?? 0,
    budget_currency: c.attributes.budget_currency ?? 'EUR',
    description: c.attributes.description ?? undefined,
    starts_at: toDateInput(c.attributes.starts_at),
    ends_at: toDateInput(c.attributes.ends_at),
    requires_per_campaign_contract: c.attributes.requires_per_campaign_contract,
    // AH-069 D1 — seeded from the stored value, NOT from the create page's
    // product default: the Settings tab shows what this campaign actually does.
    creator_posts_content: c.attributes.creator_posts_content,
    listing_duration: c.attributes.listing_duration ?? undefined,
    listing_fee: c.attributes.listing_fee ?? undefined,
    listing_languages: c.attributes.listing_languages ?? [],
    listing_regions: c.attributes.listing_regions ?? [],
    // AH-118: seeded only while the flag is on. With it off the field is left
    // out of every save, so a stored `true` can never turn an ordinary settings
    // save into a 422 `campaign.region_restriction_disabled`.
    ...(regionRestrictionEnabled.value
      ? { restrict_to_listing_regions: c.attributes.restrict_to_listing_regions }
      : {}),
    // AH-120: seeded only while the flag is on, for the same reason as the region switch:
    // with it off the fields are left out of every save, so a stored list can never turn
    // an ordinary settings save into a 422 `campaign.brand_exclusivity_disabled`.
    ...(brandExclusivityEnabled.value
      ? {
          exclusive_brand_ids: c.attributes.exclusive_brand_ids ?? [],
          exclusivity_ends_on: c.attributes.exclusivity_ends_on,
        }
      : {}),
    listing_examples_url: c.attributes.listing_examples_url ?? undefined,
  }
  editStatus.value = c.attributes.status
  editListed.value = c.attributes.listed_on_jobs_board
}

async function loadCampaign(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null
  try {
    const res = await campaignsApi.show(agencyId, ulid.value)
    campaign.value = res.data
    seedEditForm(res.data)
  } catch {
    error.value = t('app.campaigns.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

async function loadAssignments(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return
  assignmentsLoading.value = true
  try {
    const res = await campaignsApi.assignments(agencyId, ulid.value)
    assignments.value = res.data
    perCampaignContractEnabled.value = res.meta?.per_campaign_contract_enabled ?? false
  } catch {
    assignments.value = []
  } finally {
    assignmentsLoading.value = false
  }
}

/**
 * The badge's count, fetched on mount rather than only when the tab opens: a
 * badge that appears after you have already looked is not a badge. One cheap
 * page-of-one read — `meta.pending_total` is campaign-wide and unaffected by
 * pagination or the row filter.
 */
async function loadPendingApplications(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return
  try {
    const res = await campaignsApi.listApplications(agencyId, ulid.value, { per_page: 1 })
    pendingApplications.value = res.meta.pending_total
    pendingKnown.value = true
  } catch {
    // A failed count is not worth an error surface — the tab reports its own.
    pendingApplications.value = 0
    pendingKnown.value = false
  }
}

onMounted(() => {
  void loadExclusivityBrands()
  void loadCampaign()
  void loadPendingApplications()
  void loadCampaignFiles()
})

// The Overview files strip (AH-116). The Overview panel stays mounted while another tab is open,
// so a file attached, hidden or detached on the Attachments tab would leave the strip stale:
// read again each time the operator comes back to Overview.
const { files: campaignFiles, load: loadCampaignFiles } = useCampaignFiles(
  () => agencyStore.currentAgencyId,
  () => ulid.value,
)
watch(tab, (value, previous) => {
  if (value === 'overview' && previous !== 'overview') void loadCampaignFiles()
})

// `immediate`: a deep link can open ON this tab, where a change-only watch would
// never fire and the roster would stay empty.
watch(
  tab,
  (value) => {
    if (value === 'creators' && assignments.value.length === 0) {
      void loadAssignments()
    }
  },
  { immediate: true },
)

async function onSaveSettings(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || editForm.value === null) return

  saving.value = true
  saveError.value = null
  saveSuccess.value = false
  fieldErrors.value = {}

  // Brand is fixed after creation — the Settings tab never re-binds brand_id.
  const { brand_id, ...rest } = editForm.value
  void brand_id
  const payload: UpdateCampaignPayload = {
    ...rest,
    status: editStatus.value,
    listed_on_jobs_board: editListed.value,
  }

  try {
    const res = await campaignsApi.update(agencyId, ulid.value, payload)
    campaign.value = res.data
    seedEditForm(res.data)
    saveSuccess.value = true
  } catch (err) {
    if (err instanceof ApiError && err.code === 'campaign.posting_cards_present') {
      // AH-069 (D6) — the server refused to turn posting off because cards are
      // still sitting in the posting column, and its message names the creators
      // in the caller's own language. Shown verbatim, without the `[code]`
      // prefix the generic branch adds: this one is written to be read.
      saveError.value = err.message
      // Put the switch back where the server still has it. Leaving it OFF would
      // show a posture the campaign does not have.
      if (campaign.value !== null) {
        // A NEW object, not a property write: CampaignForm watches the prop by
        // reference, so mutating in place would leave the switch showing the
        // value the server just refused.
        editForm.value = {
          ...editForm.value,
          creator_posts_content: campaign.value.attributes.creator_posts_content,
        }
      }
    } else if (err instanceof ApiError && err.code === 'campaign.brand_exclusivity_disabled') {
      // AH-120: the flag was switched off under an open form.
      saveError.value = t('app.campaigns.errors.brandExclusivityDisabled')
    } else if (err instanceof ApiError) {
      const grouped = extractFieldErrors<string>(err)
      fieldErrors.value = grouped
      if (Object.keys(grouped).length === 0) {
        saveError.value = `[${err.code}] ${err.message}`
      }
    } else {
      saveError.value = t('app.campaigns.errors.saveFailed')
    }
  } finally {
    saving.value = false
  }
}

function formatMoney(minor: number | null, currency: string | null): string {
  return formatCurrency(minor, currency, locale.value)
}

function formatDay(iso: string | null): string {
  return iso ? formatDate(iso, locale.value) : '—'
}

// ── AH-114 (S3.2): the header line, and "At a glance" ───────────────────────────────────────

/**
 * The start half of the header line ("Starts Oct 8, 2026"), or null with no start date. The line
 * reads "Brand  ·  Starts Oct 8, 2026": the brand is a link (AH-116), so the header draws it
 * through the `subtitle` slot and this is only the text after the separator.
 */
const headerStarts = computed<string | null>(() => {
  const c = campaign.value
  if (c === null || c.attributes.starts_at === null) return null
  return t('app.campaigns.list.starts', { date: formatDate(c.attributes.starts_at, locale.value) })
})

/**
 * Whole days from today (local) to the start date, read from the date part of `starts_at` so a
 * time zone cannot move it by a day. `null` when there is no start date, it has passed, or the
 * campaign is over (an ended campaign has nothing to count down to).
 */
const daysUntilStart = computed<number | null>(() => {
  const c = campaign.value
  const startsAt = c?.attributes.starts_at
  if (c === null || startsAt === null || startsAt === undefined) return null
  if (c.attributes.status === 'completed' || c.attributes.status === 'cancelled') return null
  const [y, m, d] = startsAt.slice(0, 10).split('-').map(Number)
  if (!y || !m || !d) return null
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()
  const days = Math.round((new Date(y, m - 1, d).getTime() - today) / 86_400_000)
  return days >= 1 ? days : null
})

interface GlanceItem {
  key: string
  icon: string
  label: string
  value: string
}

/**
 * The right panel (360, Figma "At a glance") holds only what the page can supply: the pending
 * applications count and the days until start. The frame's "creators confirmed 0 of 6", "budget
 * used" and "next steps" have no data behind them (ruling 15). With fewer than two rows left the
 * panel is not drawn at all (P5): one row alone is not a panel.
 */
const glanceItems = computed<GlanceItem[]>(() => {
  const items: GlanceItem[] = []
  if (pendingKnown.value) {
    items.push({
      key: 'applications',
      icon: 'mdi-file-document-outline',
      label: t('app.campaigns.tabs.applications'),
      value: String(pendingApplications.value),
    })
  }
  if (daysUntilStart.value !== null) {
    items.push({
      key: 'days-until-start',
      icon: 'mdi-calendar-outline',
      label: t('app.campaigns.detail.glance.daysUntilStart'),
      value: String(daysUntilStart.value),
    })
  }
  return items
})
const showGlance = computed(() => glanceItems.value.length >= 2)
// The right panel draws when there is anything for it: the glance rows (two or more) or files
// (AH-116). The glance block keeps its own two-row rule inside it.
const showAside = computed(() => showGlance.value || campaignFiles.value.length > 0)

// ── AH-118 (W1): the region restriction on Overview ─────────────────────────────────────────

/** The listing regions as country names (a code the list does not know shows as itself). */
const regionNames = computed<string[]>(() =>
  (campaign.value?.attributes.listing_regions ?? []).map(
    (code) => COUNTRY_OPTIONS.find((c) => c.code === code)?.label ?? code,
  ),
)

/** AH-120: the blocked brands' names for Overview; an archived one stays listed, marked. */
const exclusiveBrandsShown = computed<string[]>(() =>
  (campaign.value?.attributes.exclusive_brands ?? []).map((b) =>
    b.is_archived ? `${b.name} (${t('app.brands.status.archived')})` : b.name,
  ),
)

const restrictOn = computed(() => campaign.value?.attributes.restrict_to_listing_regions === true)

/**
 * Same rule as the Settings form: the switch needs a listing with regions to mean anything.
 * Turning an already-ON switch OFF is never gated (as the jobs-board switch), so a campaign
 * that was delisted or lost its regions cannot be stuck restricted.
 */
const restrictToggleDisabled = computed(() => {
  const c = campaign.value
  if (c === null || !canEdit.value || restrictSaving.value) return true
  if (restrictOn.value) return false
  return c.attributes.listed_on_jobs_board !== true || regionNames.value.length === 0
})

const restrictSaving = ref(false)
const restrictError = ref<string | null>(null)
// A refused flip leaves `restrictOn` unchanged, so Vue sees no change and the switch would keep
// the position the user clicked. Bumping the key redraws it from the server's value.
const restrictSwitchKey = ref(0)

/** PATCH only this field; the switch always shows the server's value (it is not a local copy). */
async function onToggleRestrict(value: boolean | null): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || campaign.value === null) return
  restrictSaving.value = true
  restrictError.value = null
  try {
    const res = await campaignsApi.update(agencyId, ulid.value, {
      restrict_to_listing_regions: value === true,
    })
    campaign.value = res.data
    // Keep the Settings form in step without discarding what is typed there.
    if (editForm.value !== null) {
      editForm.value = {
        ...editForm.value,
        restrict_to_listing_regions: res.data.attributes.restrict_to_listing_regions,
      }
    }
  } catch (err) {
    restrictError.value =
      err instanceof ApiError ? err.message : t('app.campaigns.errors.saveFailed')
    restrictSwitchKey.value += 1
  } finally {
    restrictSaving.value = false
  }
}

interface RequirementRow {
  key: string
  label: string
  value: boolean
  test: string
}

/** The three on/off campaign settings, in the Figma "Requirements" card's order. */
const requirementRows = computed<RequirementRow[]>(() => {
  const c = campaign.value
  if (c === null) return []
  return [
    {
      key: 'contract',
      label: t('app.campaigns.fields.requiresContract'),
      value: c.attributes.requires_per_campaign_contract === true,
      test: 'overview-requires-contract',
    },
    {
      key: 'creator-posts',
      label: t('app.campaigns.fields.creatorPostsContent'),
      value: c.attributes.creator_posts_content === true,
      test: 'overview-creator-posts-content',
    },
    {
      key: 'job-board',
      label: t('app.campaigns.fields.listedOnJobsBoard'),
      value: c.attributes.listed_on_jobs_board === true,
      test: 'overview-listed-on-jobs-board',
    },
  ]
})
</script>

<template>
  <div class="agency-page agency-page--snug" data-test="campaign-detail-page">
    <AgencyPageHeader
      :title="campaign?.attributes.name ?? t('app.campaigns.detail.title')"
      title-test="campaign-detail-heading"
      subtitle-test="campaign-detail-subtitle"
    >
      <template v-if="campaign !== null" #subtitle>
        <router-link
          :to="{ name: 'brands.detail', params: { ulid: campaign.relationships.brand.data.id } }"
          class="agency-entity-link"
          data-test="campaign-detail-brand-link"
          >{{ campaign.relationships.brand.data.name }}</router-link
        >
        <template v-if="headerStarts !== null">{{ '  ·  ' }}{{ headerStarts }}</template>
      </template>
      <template #leading>
        <v-btn
          icon="mdi-arrow-left"
          variant="text"
          :to="{ name: 'campaigns.list' }"
          class="agency-icon-square"
          :aria-label="t('app.campaigns.actions.backToList')"
          data-test="campaign-detail-back"
        />
      </template>
      <template v-if="campaign" #title-suffix>
        <AgencyStatusPill
          kind="campaign"
          :status="campaign.attributes.status"
          data-test="campaign-detail-status"
        />
      </template>
      <template v-if="campaign && canInvite" #actions>
        <v-btn
          color="primary"
          variant="flat"
          data-test="campaign-detail-invite"
          @click="openInvite"
        >
          {{ t('app.campaigns.invite.open') }}
        </v-btn>
      </template>
    </AgencyPageHeader>

    <v-alert v-if="error" type="error" variant="tonal" data-test="campaign-detail-error">
      {{ error }}
    </v-alert>

    <v-skeleton-loader
      v-if="loading && !campaign"
      type="article"
      data-test="campaign-detail-skeleton"
    />

    <template v-else-if="campaign">
      <v-tabs v-model="tab" class="agency-tabs" data-test="campaign-tabs">
        <v-tab value="overview" data-test="tab-overview">{{
          t('app.campaigns.tabs.overview')
        }}</v-tab>
        <v-tab value="attachments" data-test="tab-attachments">{{
          t('app.campaigns.tabs.attachments')
        }}</v-tab>
        <v-tab value="creators" data-test="tab-creators">{{
          t('app.campaigns.tabs.creators')
        }}</v-tab>
        <v-tab value="board" data-test="tab-board">{{ t('app.campaigns.tabs.board') }}</v-tab>
        <v-tab value="drafts" data-test="tab-drafts">{{ t('app.campaigns.tabs.drafts') }}</v-tab>
        <v-tab value="applications" data-test="tab-applications">
          {{ t('app.campaigns.tabs.applications') }}
          <v-badge
            v-if="pendingApplications > 0"
            inline
            color="primary"
            :content="pendingApplications"
            data-test="tab-applications-badge"
          />
        </v-tab>
        <v-tab value="payments" data-test="tab-payments">{{
          t('app.campaigns.tabs.payments')
        }}</v-tab>
        <v-tab value="messages" data-test="tab-messages">{{
          t('app.campaigns.tabs.messages')
        }}</v-tab>
        <v-tab v-if="canEdit" value="settings" data-test="tab-settings">
          {{ t('app.campaigns.tabs.settings') }}
        </v-tab>
      </v-tabs>

      <v-window v-model="tab">
        <!-- Overview -->
        <v-window-item value="overview" data-test="panel-overview">
          <div class="agency-detail" :class="{ 'agency-detail--with-panel': showAside }">
            <div class="agency-detail__main">
              <section class="agency-card" data-test="overview-details-card">
                <h2 class="agency-card__title">{{ t('app.campaigns.detail.title') }}</h2>

                <dl class="agency-fields">
                  <div class="agency-field">
                    <dt class="agency-field__label">{{ t('app.campaigns.fields.brand') }}</dt>
                    <dd class="agency-field__value" data-test="overview-brand">
                      <router-link
                        :to="{
                          name: 'brands.detail',
                          params: { ulid: campaign.relationships.brand.data.id },
                        }"
                        class="agency-entity-link"
                        data-test="overview-brand-link"
                        >{{ campaign.relationships.brand.data.name }}</router-link
                      >
                    </dd>
                  </div>
                  <div class="agency-field">
                    <dt class="agency-field__label">{{ t('app.campaigns.fields.status') }}</dt>
                    <dd class="agency-field__value">
                      <AgencyStatusPill
                        kind="campaign"
                        :status="campaign.attributes.status"
                        data-test="overview-status"
                      />
                    </dd>
                  </div>
                  <div class="agency-field">
                    <dt class="agency-field__label">{{ t('app.campaigns.fields.budget') }}</dt>
                    <dd class="agency-field__value" data-test="overview-budget">
                      {{
                        formatMoney(
                          campaign.attributes.budget_minor_units,
                          campaign.attributes.budget_currency,
                        )
                      }}
                    </dd>
                  </div>
                  <div class="agency-field">
                    <dt class="agency-field__label">{{ t('app.campaigns.fields.startsAt') }}</dt>
                    <dd class="agency-field__value" data-test="overview-starts-at">
                      {{ formatDay(campaign.attributes.starts_at) }}
                    </dd>
                  </div>
                  <div class="agency-field">
                    <dt class="agency-field__label">{{ t('app.campaigns.fields.endsAt') }}</dt>
                    <dd class="agency-field__value" data-test="overview-ends-at">
                      {{ formatDay(campaign.attributes.ends_at) }}
                    </dd>
                  </div>
                  <div
                    v-if="regionNames.length > 0"
                    class="agency-field"
                    style="grid-column: 1 / -1"
                    data-test="overview-regions-field"
                  >
                    <dt class="agency-field__label">
                      {{ t('app.campaigns.fields.listingRegions') }}
                    </dt>
                    <dd class="agency-field__value" style="flex-wrap: wrap; gap: 8px">
                      <span data-test="overview-regions">{{ regionNames.join(', ') }}</span>
                      <AgencyStatusPill
                        v-if="regionRestrictionEnabled && restrictOn"
                        tone="warning"
                        :label="t('app.campaigns.fields.regionsRestricted')"
                        data-test="overview-regions-restricted"
                      />
                    </dd>
                  </div>
                  <!-- AH-120: who this campaign keeps from applying to which brands. -->
                  <div
                    v-if="brandExclusivityEnabled && exclusiveBrandsShown.length > 0"
                    class="agency-field"
                    style="grid-column: 1 / -1"
                    data-test="overview-exclusive-brands-field"
                  >
                    <dt class="agency-field__label">
                      {{ t('app.campaigns.fields.exclusiveBrandsOverview') }}
                    </dt>
                    <dd class="agency-field__value" style="flex-wrap: wrap; gap: 8px">
                      <span data-test="overview-exclusive-brands">{{
                        exclusiveBrandsShown.join(', ')
                      }}</span>
                      <AgencyStatusPill
                        v-if="campaign.attributes.exclusivity_ends_on"
                        tone="neutral"
                        :label="
                          t('app.campaigns.fields.exclusivityEndsOnOverview', {
                            date: formatDay(campaign.attributes.exclusivity_ends_on),
                          })
                        "
                        data-test="overview-exclusivity-ends-on"
                      />
                    </dd>
                  </div>
                </dl>

                <template v-if="campaign.attributes.description">
                  <hr class="agency-card__rule" />
                  <div class="agency-field overview-description" data-test="overview-description">
                    <h3 class="agency-field__label">
                      {{ t('app.campaigns.fields.description') }}
                    </h3>
                    <!-- Minimal rich text (AH-081) via RichBrief. -->
                    <RichBrief
                      :text="campaign.attributes.description"
                      class="overview-description__text agency-field__text"
                    />
                  </div>
                </template>
              </section>

              <!-- The three campaign settings, read-only here: they are edited in Settings
                   (and the job board one from the list). -->
              <section class="agency-card" data-test="overview-requirements-card">
                <h2 class="agency-card__title">{{ t('app.campaigns.tabs.settings') }}</h2>
                <div
                  v-for="row in requirementRows"
                  :key="row.key"
                  class="agency-switch-row"
                  :data-test="`overview-requirement-${row.key}`"
                >
                  <span class="agency-switch-row__label">{{ row.label }}</span>
                  <v-switch
                    :model-value="row.value"
                    readonly
                    inset
                    hide-details
                    density="compact"
                    color="primary"
                    :aria-label="row.label"
                    :data-test="row.test"
                  />
                </div>
                <!-- AH-118: the one switch here that edits (PATCHes by itself); hidden while
                     the region flag is off. -->
                <div v-if="regionRestrictionEnabled" data-test="overview-requirement-restrict">
                  <div class="agency-switch-row">
                    <span class="agency-switch-row__label">{{
                      t('app.campaigns.fields.restrictToRegions')
                    }}</span>
                    <v-switch
                      :key="restrictSwitchKey"
                      :model-value="restrictOn"
                      :disabled="restrictToggleDisabled"
                      :loading="restrictSaving"
                      inset
                      hide-details
                      density="compact"
                      color="primary"
                      :aria-label="t('app.campaigns.fields.restrictToRegions')"
                      data-test="overview-restrict-to-regions"
                      @update:model-value="onToggleRestrict"
                    />
                  </div>
                  <p class="agency-field__label" style="text-transform: none; letter-spacing: 0">
                    {{ t('app.campaigns.fields.restrictToRegionsHint') }}
                  </p>
                  <p
                    v-if="restrictError !== null"
                    class="text-error"
                    role="alert"
                    data-test="overview-restrict-error"
                  >
                    {{ restrictError }}
                  </p>
                </div>
              </section>
            </div>

            <aside v-if="showAside" class="agency-detail__panel" data-test="overview-aside">
              <section v-if="showGlance" class="agency-card" data-test="overview-glance">
                <h2 class="agency-card__title">{{ t('app.campaigns.detail.glance.title') }}</h2>
                <div
                  v-for="item in glanceItems"
                  :key="item.key"
                  class="agency-glance-row"
                  :data-test="`overview-glance-${item.key}`"
                >
                  <span class="agency-glance-row__tile" aria-hidden="true">
                    <v-icon :icon="item.icon" size="16" />
                  </span>
                  <span class="agency-glance-row__label">{{ item.label }}</span>
                  <span class="agency-glance-row__value">{{ item.value }}</span>
                </div>
              </section>
              <!-- A sibling of the glance block, not inside it: files can stand alone. -->
              <CampaignFilesStrip
                :files="campaignFiles"
                mode="overview"
                @view-all="tab = 'attachments'"
              />
            </aside>
          </div>
        </v-window-item>

        <!-- Attachments (Campaign attachments from the Files library, chunk B,
             AH-092) — fetch-on-open, the Drafts/Applications precedent. -->
        <v-window-item value="attachments" data-test="panel-attachments">
          <AttachmentsTab
            v-if="tab === 'attachments' && agencyStore.currentAgencyId"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-id="ulid"
            :can-edit="canEdit"
          />
        </v-window-item>

        <!-- Creators (assignment list + invite picker) -->
        <v-window-item value="creators" data-test="panel-creators">
          <div class="d-flex justify-end mb-3">
            <v-btn
              v-if="canInvite"
              color="primary"
              variant="flat"
              prepend-icon="mdi-account-plus-outline"
              data-test="invite-creators-open"
              @click="inviteDialog = true"
            >
              {{ t('app.campaigns.invite.open') }}
            </v-btn>
          </div>

          <v-skeleton-loader v-if="assignmentsLoading" type="list-item-two-line@3" />
          <CEmptyState
            v-else-if="assignments.length === 0"
            data-test="creators-empty-state"
            title-tag="h2"
            :title="t('app.campaigns.creators.empty.heading')"
            :body="t('app.campaigns.creators.empty.body')"
          >
            <template #icon>
              <v-icon icon="mdi-account-multiple-outline" size="56" color="medium-emphasis" />
            </template>
          </CEmptyState>
          <v-list v-else data-test="creators-list">
            <!-- AH-080 (D2a) — row click opens the creator profile dialog;
                 avatar added to the identity (AH-075 precedent). The append
                 actions stop propagation so they keep firing on their own,
                 without also popping the profile open underneath (D5). -->
            <v-list-item
              v-for="a in assignments"
              :key="a.id"
              :data-test="`creators-row-${a.id}`"
              link
              @click="openProfile(a)"
            >
              <template #prepend>
                <v-avatar size="40" class="mr-3" :data-test="`creators-avatar-${a.id}`">
                  <v-img
                    v-if="creatorAvatarUrl(a.attributes.creator)"
                    :src="creatorAvatarUrl(a.attributes.creator) ?? undefined"
                    :alt="creatorAvatarAlt(a.attributes.creator)"
                  />
                  <span v-else class="text-body-2">
                    {{ creatorInitial(a.attributes.creator) }}
                  </span>
                </v-avatar>
              </template>
              <v-list-item-title class="d-flex align-center ga-2">
                {{ creatorLabel(a.attributes.creator, t, '—') }}
                <!-- History tag: this row was declined, then re-offered
                     (re-offer-after-decline chunk). Shown alongside the live
                     status so the agency sees "declined → re-invited". Reuses
                     the declined status label. -->
                <AgencyStatusPill
                  v-if="a.attributes.previously_declined && a.attributes.status !== 'declined'"
                  tone="neutral"
                  :label="t('app.campaigns.assignmentStatus.declined')"
                  :data-test="`creators-declined-history-${a.id}`"
                />
                <AgencyStatusPill
                  kind="assignment"
                  :status="a.attributes.status"
                  :data-test="`creators-status-${a.id}`"
                />
              </v-list-item-title>
              <v-list-item-subtitle :data-test="`creators-fees-${a.id}`">
                <template v-if="a.attributes.status === 'countered'">
                  {{ t('app.campaigns.fees.offered') }}:
                  {{
                    formatMoney(
                      a.attributes.agreed_fee_minor_units,
                      a.attributes.agreed_fee_currency,
                    )
                  }}
                  · {{ t('app.campaigns.fees.countered') }}:
                  {{
                    formatMoney(
                      a.attributes.countered_fee_minor_units,
                      a.attributes.countered_fee_currency,
                    )
                  }}
                </template>
                <template v-else>
                  {{
                    formatMoney(
                      a.attributes.agreed_fee_minor_units,
                      a.attributes.agreed_fee_currency,
                    )
                  }}<template v-if="a.attributes.fee_per"> / {{ a.attributes.fee_per }}</template>
                </template>
              </v-list-item-subtitle>
              <template #append>
                <v-btn
                  v-if="canShowEditOffer(a)"
                  variant="tonal"
                  size="small"
                  class="mr-2"
                  :data-test="`creators-edit-offer-${a.id}`"
                  :data-locked="a.attributes.offer_edit_locked === true ? 'true' : 'false'"
                  @click.stop="openEditOffer(a)"
                >
                  {{ t('app.campaigns.offerEdit.action') }}
                </v-btn>
                <v-btn
                  v-if="a.attributes.status === 'countered' && canInvite"
                  color="primary"
                  variant="flat"
                  size="small"
                  :data-test="`creators-reinvite-${a.id}`"
                  @click.stop="openReinvite(a)"
                >
                  {{ t('app.campaigns.reinvite.action') }}
                </v-btn>
                <v-btn
                  v-if="
                    a.attributes.status === 'accepted' &&
                    canAttachContract &&
                    a.attributes.has_pending_contract !== true
                  "
                  color="primary"
                  variant="flat"
                  size="small"
                  :data-test="`creators-attach-contract-${a.id}`"
                  @click.stop="openAttachContract(a)"
                >
                  {{ t('app.campaigns.contract.attach.action') }}
                </v-btn>
                <AgencyStatusPill
                  v-if="
                    a.attributes.status === 'accepted' && a.attributes.has_pending_contract === true
                  "
                  tone="info"
                  :label="t('app.campaigns.contract.pending')"
                  :data-test="`creators-contract-pending-${a.id}`"
                />
                <v-btn
                  v-if="canProceedWithoutContract(a)"
                  color="secondary"
                  variant="outlined"
                  size="small"
                  :data-test="`creators-proceed-without-contract-${a.id}`"
                  @click.stop="proceedWithoutContract(a)"
                >
                  {{ t('app.campaigns.contract.proceedWithout.action') }}
                </v-btn>
                <v-btn
                  v-if="a.attributes.status === 'draft_submitted' && canReview"
                  color="primary"
                  variant="flat"
                  size="small"
                  :data-test="`creators-review-${a.id}`"
                  @click.stop="openReview(a)"
                >
                  {{ t('app.campaigns.review.action') }}
                </v-btn>
                <v-btn
                  v-if="canResolveVerification(a)"
                  color="warning"
                  variant="flat"
                  size="small"
                  :data-test="`creators-resolve-${a.id}`"
                  @click.stop="openResolve(a)"
                >
                  {{ t('app.campaigns.resolution.action') }}
                </v-btn>
                <v-btn
                  v-if="canViewPost(a)"
                  color="primary"
                  variant="outlined"
                  size="small"
                  :data-test="`creators-view-post-${a.id}`"
                  @click.stop="openViewPost(a)"
                >
                  {{ t('app.campaigns.viewPost.action') }}
                </v-btn>
              </template>
            </v-list-item>
          </v-list>

          <CreatorProfileDialog
            v-if="agencyStore.currentAgencyId && profileTarget?.attributes.creator"
            v-model="profileDialog"
            :agency-id="agencyStore.currentAgencyId"
            :creator-ulid="profileTarget.attributes.creator.id"
            :assume-full="false"
          />

          <InviteCreatorsDialog
            v-if="canInvite && agencyStore.currentAgencyId"
            v-model="inviteDialog"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-id="ulid"
            :campaign-currency="campaign?.attributes.budget_currency ?? null"
            :exclusivity-enabled="brandExclusivityEnabled"
            @invited="onInvited"
          />

          <ReinviteDialog
            v-if="canInvite && agencyStore.currentAgencyId"
            v-model="reinviteDialog"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-id="ulid"
            :assignment="reinviteTarget"
            :campaign-currency="campaign?.attributes.budget_currency ?? null"
            @success="onReinvited"
          />

          <EditOfferDialog
            v-if="offerEditEnabled && canInvite && agencyStore.currentAgencyId"
            v-model="editOfferDialog"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-id="ulid"
            :assignment-id="editOfferTarget?.id ?? null"
            :current-minor="editOfferTarget?.attributes.agreed_fee_minor_units ?? null"
            :currency="
              editOfferTarget?.attributes.agreed_fee_currency ??
              campaign?.attributes.budget_currency ??
              null
            "
            :lock-reason="editOfferTarget?.attributes.offer_edit_lock_reason ?? null"
            @success="onOfferEdited"
          />

          <v-snackbar
            :model-value="editOfferSnackbar !== null"
            :timeout="4000"
            color="success"
            data-test="edit-offer-snackbar"
            @update:model-value="
              (v) => {
                if (!v) editOfferSnackbar = null
              }
            "
          >
            {{ editOfferSnackbar }}
          </v-snackbar>

          <AttachContractDialog
            v-if="canAttachContract && agencyStore.currentAgencyId"
            v-model="attachContractDialog"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-id="ulid"
            :assignment="attachContractTarget"
            @success="onContractAttached"
            @error="onContractAttachError"
          />

          <v-snackbar
            :model-value="inviteSnackbar !== null"
            :timeout="4000"
            color="success"
            data-test="invite-creators-snackbar"
            @update:model-value="
              (v) => {
                if (!v) inviteSnackbar = null
              }
            "
          >
            {{ inviteSnackbar }}
          </v-snackbar>

          <v-snackbar
            :model-value="reinviteSnackbar !== null"
            :timeout="4000"
            color="success"
            data-test="reinvite-snackbar"
            @update:model-value="
              (v) => {
                if (!v) reinviteSnackbar = null
              }
            "
          >
            {{ reinviteSnackbar }}
          </v-snackbar>

          <v-snackbar
            :model-value="attachContractSnackbar !== null"
            :timeout="4000"
            color="success"
            data-test="attach-contract-snackbar"
            @update:model-value="
              (v) => {
                if (!v) attachContractSnackbar = null
              }
            "
          >
            {{ attachContractSnackbar }}
          </v-snackbar>

          <v-snackbar
            :model-value="attachContractError !== null"
            :timeout="5000"
            color="error"
            data-test="attach-contract-error-snackbar"
            @update:model-value="
              (v) => {
                if (!v) attachContractError = null
              }
            "
          >
            {{ attachContractError }}
          </v-snackbar>

          <v-snackbar
            :model-value="proceedSnackbar !== null"
            :timeout="4000"
            color="success"
            data-test="proceed-without-contract-snackbar"
            @update:model-value="
              (v) => {
                if (!v) proceedSnackbar = null
              }
            "
          >
            {{ proceedSnackbar }}
          </v-snackbar>

          <v-snackbar
            :model-value="proceedError !== null"
            :timeout="5000"
            color="error"
            data-test="proceed-without-contract-error"
            @update:model-value="
              (v) => {
                if (!v) proceedError = null
              }
            "
          >
            {{ proceedError }}
          </v-snackbar>
        </v-window-item>

        <!-- Board (Sprint 12) — the Kanban. Mounted with v-if so the 30s poll
             stops when the operator leaves the tab (§10.2 / Q3 — no background
             polling on an unviewed tab). -->
        <v-window-item value="board" data-test="panel-board">
          <BoardView
            v-if="tab === 'board' && agencyStore.currentAgencyId"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-id="ulid"
            :brand-id="campaign?.relationships.brand.data.id ?? null"
            :can-configure="canEdit"
            :can-resolve="canReview"
            :can-review="canReview"
            :can-edit-offer="offerEditEnabled && canInvite"
            :can-act="canInvite"
            :campaign-currency="campaign?.attributes.budget_currency ?? null"
            @resolve="openResolve"
          />
        </v-window-item>

        <!-- Drafts — campaign-wide version list; fetch-on-open (Board precedent). -->
        <v-window-item value="drafts" data-test="panel-drafts">
          <DraftsTab
            v-if="tab === 'drafts' && agencyStore.currentAgencyId"
            ref="draftsTabRef"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-id="ulid"
            :can-review="canReview"
            @open-review="openReview"
            @open-resolve="openResolve"
          />
        </v-window-item>

        <!-- Applications (Jobs Board chunk 4) — the agency half of the jobs
             board; fetch-on-open, like Board and Drafts. -->
        <v-window-item value="applications" data-test="panel-applications">
          <ApplicationsTab
            v-if="tab === 'applications' && agencyStore.currentAgencyId"
            ref="applicationsTabRef"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-id="ulid"
            :can-act="canInvite"
            :campaign-currency="campaign.attributes.budget_currency"
            @pending-total="(n) => (pendingApplications = n)"
            @answered="onApplicationAnswered"
          />

          <v-snackbar
            :model-value="applicationsSnackbar !== null"
            :timeout="4000"
            color="success"
            data-test="applications-snackbar"
            @update:model-value="
              (v) => {
                if (!v) applicationsSnackbar = null
              }
            "
          >
            {{ applicationsSnackbar }}
          </v-snackbar>
        </v-window-item>

        <!-- Messages (Sprint 11) — the agency roll-up of the campaign's threads -->
        <v-window-item value="messages" data-test="panel-messages">
          <CampaignMessagesPanel
            v-if="agencyStore.currentAgencyId"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-ulid="ulid"
          />
        </v-window-item>

        <!-- Coming-soon tabs -->
        <v-window-item
          v-for="key in comingSoonTabs"
          :key="key"
          :value="key"
          :data-test="`panel-${key}`"
        >
          <CEmptyState
            :data-test="`${key}-coming-soon`"
            title-tag="h2"
            :title="t(`app.campaigns.comingSoon.${key}.heading`)"
            :body="t(`app.campaigns.comingSoon.${key}.body`)"
          >
            <template #icon>
              <v-icon icon="mdi-clock-outline" size="56" color="medium-emphasis" />
            </template>
          </CEmptyState>
        </v-window-item>

        <!-- Settings (admin/manager) -->
        <v-window-item v-if="canEdit" value="settings" data-test="panel-settings">
          <v-card class="pa-6" max-width="720">
            <v-select
              v-model="editStatus"
              :label="t('app.campaigns.fields.status')"
              :items="statusOptions"
              item-title="title"
              item-value="value"
              data-test="campaign-settings-status"
            />

            <!--
              Jobs board (AH-054, D4) — the switch sits next to status, the
              per-campaign-contract switch as its shape precedent. Disabled
              (never hidden) while the listing floor is unmet or the campaign
              is terminal, with the reason named in the hint.
            -->
            <v-switch
              v-model="editListed"
              :label="t('app.campaigns.fields.listedOnJobsBoard')"
              :hint="listingToggleHint"
              :error-messages="listedFieldErrors"
              :disabled="listingToggleDisabled"
              persistent-hint
              color="primary"
              density="compact"
              class="mb-4"
              data-test="campaign-settings-listed"
            />

            <CampaignForm
              v-if="editForm"
              v-model="editForm"
              :brands="exclusivityBrandOptions"
              hide-brand
              :own-brand-id="campaign?.relationships.brand.data.id ?? null"
              :listed-exclusive-brands="campaign?.attributes.exclusive_brands ?? []"
              :stored-exclusivity-ends-on="campaign?.attributes.exclusivity_ends_on ?? null"
              :submitting="saving"
              :submit-label="t('app.campaigns.actions.saveSettings')"
              :error="saveError"
              :field-errors="fieldErrors"
              :region-restriction-enabled="regionRestrictionEnabled"
              :brand-exclusivity-enabled="brandExclusivityEnabled"
              @submit="onSaveSettings"
            />
            <v-snackbar
              :model-value="saveSuccess"
              :timeout="3000"
              color="success"
              data-test="settings-success-toast"
              @update:model-value="
                (v) => {
                  if (!v) saveSuccess = false
                }
              "
            >
              {{ t('app.campaigns.settings.saved') }}
            </v-snackbar>
          </v-card>
        </v-window-item>
      </v-window>

      <ReviewDraftDrawer
        v-if="canReview && agencyStore.currentAgencyId"
        v-model="reviewDialog"
        :agency-id="agencyStore.currentAgencyId"
        :campaign-id="ulid"
        :assignment="reviewTarget"
        @reviewed="onReviewed"
      />

      <ResolveVerificationDrawer
        v-if="canReview && agencyStore.currentAgencyId"
        v-model="resolveDialog"
        :agency-id="agencyStore.currentAgencyId"
        :campaign-id="ulid"
        :assignment="resolveTarget"
        @resolved="onResolved"
      />

      <ViewPostedContentDrawer
        v-if="canReview && agencyStore.currentAgencyId"
        v-model="viewPostDialog"
        :agency-id="agencyStore.currentAgencyId"
        :campaign-id="ulid"
        :assignment="viewPostTarget"
      />

      <v-snackbar
        :model-value="reviewSnackbar !== null"
        :timeout="4000"
        color="success"
        data-test="review-snackbar"
        @update:model-value="
          (v) => {
            if (!v) reviewSnackbar = null
          }
        "
      >
        {{ reviewSnackbar }}
      </v-snackbar>
    </template>
  </div>
</template>

<style scoped>
.overview-description__text {
  /* RichBrief's own <p>/<br> markup owns line breaks now (AH-081) — pre-wrap
     would additionally render the sanitized HTML's literal whitespace
     between tags, doubling every break into a blank line. */
  word-break: break-word;
}
</style>
