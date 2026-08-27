<script setup lang="ts">
/**
 * Platform automations — the operator's rules table (AH-090, D6).
 *
 * Lists every configured rule with its target, state and fire trace, and owns
 * the create / edit / toggle / delete interactions. Every write takes a reason
 * (all four `automation_rule.*` audit verbs requiresReason(), so a reasonless
 * call is a 422) and the rendered row is replaced with the SERVER's response, so
 * the table never shows a state the backend did not confirm.
 *
 * A broken rule — one whose target agency has been deleted, suspended, or was
 * never named — is rendered as broken rather than hidden. The backend job
 * already refuses to fire in those states; hiding it here would leave an
 * operator staring at a rule that silently never does anything.
 *
 * Note what this page CANNOT do: arm the feature. `platform_automations_enabled`
 * is the kill switch and it lives on the feature-flags page, so a rule created
 * here does nothing until someone deliberately flips that flag.
 */

import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import {
  adminAutomationsApi,
  type AdminAgencyOption,
  type AdminAutomationRule,
  type AutomationAction,
  type AutomationEvent,
} from '../api/automations.api'

const { t } = useI18n()

const EVENTS: AutomationEvent[] = ['creator.approved']
const ACTIONS: AutomationAction[] = ['connect_to_agency']

/**
 * Enum value → i18n leaf. Backend event values are dotted (`creator.approved`)
 * and vue-i18n reads a dot as a path separator, so they cannot be leaf keys.
 */
const EVENT_KEYS: Record<AutomationEvent, string> = { 'creator.approved': 'creatorApproved' }
const ACTION_KEYS: Record<AutomationAction, string> = { connect_to_agency: 'connectToAgency' }
const MIN_REASON = 10
const MIN_NAME = 3

const items = ref<AdminAutomationRule[]>([])
const agencies = ref<AdminAgencyOption[]>([])
const loading = ref(false)
const errorKey = ref<string | null>(null)
const pending = ref(false)

const snackbar = ref<{ show: boolean; messageKey: string; color: string }>({
  show: false,
  messageKey: '',
  color: 'success',
})

/** The create/edit dialog. `editing` null = create. */
const formDialog = ref(false)
const editing = ref<AdminAutomationRule | null>(null)
const form = ref<{
  name: string
  event: AutomationEvent
  action: AutomationAction
  agencyId: string | null
  reason: string
}>({
  name: '',
  event: 'creator.approved',
  action: 'connect_to_agency',
  agencyId: null,
  reason: '',
})

const toggleDialog = ref(false)
const toggleTarget = ref<{ rule: AdminAutomationRule; next: boolean } | null>(null)
const toggleReason = ref('')

const deleteDialog = ref(false)
const deleteTarget = ref<AdminAutomationRule | null>(null)
const deleteReason = ref('')

const formValid = computed(
  () =>
    form.value.name.trim().length >= MIN_NAME &&
    form.value.agencyId !== null &&
    form.value.reason.trim().length >= MIN_REASON,
)
const toggleValid = computed(() => toggleReason.value.trim().length >= MIN_REASON)
const deleteValid = computed(() => deleteReason.value.trim().length >= MIN_REASON)

const agencyOptions = computed(() =>
  agencies.value.map((agency) => ({ value: agency.id, title: agency.attributes.name })),
)

const eventOptions = computed(() =>
  EVENTS.map((event) => ({ value: event, title: eventLabel(event) })),
)

const actionOptions = computed(() =>
  ACTIONS.map((action) => ({ value: action, title: actionLabel(action) })),
)

/**
 * Label an event or action, falling back to the raw stored value.
 *
 * A rule row can name an event this build has never heard of — a rule written by
 * a newer version, or hand-edited. Showing the raw token is honest; a
 * missing-translation blank would hide that a rule exists at all.
 */
function eventLabel(event: string): string {
  const leaf = EVENT_KEYS[event as AutomationEvent]
  return leaf === undefined ? event : t(`admin.automations.events.${leaf}`)
}

function actionLabel(action: string): string {
  const leaf = ACTION_KEYS[action as AutomationAction]
  return leaf === undefined ? action : t(`admin.automations.actions.${leaf}`)
}

function notify(messageKey: string, color: string): void {
  snackbar.value = { show: true, messageKey, color }
}

async function load(): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const [rules, options] = await Promise.all([
      adminAutomationsApi.list(),
      adminAutomationsApi.agencyOptions(),
    ])
    items.value = rules.data
    agencies.value = options.data
  } catch {
    // Deliberately NOT the ApiError code: the sibling feature-flags page feeds
    // `error.code` into t(), and since no locale defines `auth.forbidden` &c.
    // that renders the raw token at the operator. One key we own, always.
    errorKey.value = 'admin.automations.load_failed'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

/**
 * Rules already watching the event in the form. Shown in the dialog so a
 * duplicate is visible BEFORE it is created — the backend has no uniqueness
 * constraint (two rules naming the same agency are safe, because the connect is
 * idempotent), and absorbing a duplicate after the fact is worse than not
 * creating it blind.
 */
const existingForEvent = computed(() =>
  items.value.filter(
    (rule) => rule.attributes.event === form.value.event && rule.id !== editing.value?.id,
  ),
)

function openCreate(): void {
  editing.value = null
  form.value = {
    name: '',
    event: 'creator.approved',
    action: 'connect_to_agency',
    agencyId: null,
    reason: '',
  }
  formDialog.value = true
}

function openEdit(rule: AdminAutomationRule): void {
  editing.value = rule
  form.value = {
    name: rule.attributes.name,
    event: rule.attributes.event,
    action: rule.attributes.action,
    agencyId: rule.attributes.target_agency_id,
    reason: '',
  }
  formDialog.value = true
}

function closeForm(): void {
  formDialog.value = false
  editing.value = null
}

async function submitForm(): Promise<void> {
  if (!formValid.value || form.value.agencyId === null) return
  pending.value = true
  try {
    const payload = {
      name: form.value.name.trim(),
      event: form.value.event,
      action: form.value.action,
      config: { agency_id: form.value.agencyId },
      reason: form.value.reason.trim(),
    }

    if (editing.value === null) {
      const res = await adminAutomationsApi.create(payload)
      items.value = [res.data, ...items.value]
      notify('admin.automations.form.created', 'success')
    } else {
      const res = await adminAutomationsApi.update(editing.value.id, payload)
      replaceRow(res.data)
      notify('admin.automations.form.updated', 'success')
    }
    closeForm()
  } catch {
    notify('admin.automations.form.failed', 'error')
  } finally {
    pending.value = false
  }
}

function requestToggle(rule: AdminAutomationRule): void {
  toggleTarget.value = { rule, next: !rule.attributes.enabled }
  toggleReason.value = ''
  toggleDialog.value = true
}

async function confirmToggle(): Promise<void> {
  if (toggleTarget.value === null || !toggleValid.value) return
  pending.value = true
  try {
    const res = await adminAutomationsApi.toggle(
      toggleTarget.value.rule.id,
      toggleTarget.value.next,
      toggleReason.value.trim(),
    )
    replaceRow(res.data)
    toggleDialog.value = false
    toggleTarget.value = null
    notify('admin.automations.toggle.success', 'success')
  } catch {
    notify('admin.automations.toggle.failed', 'error')
  } finally {
    pending.value = false
  }
}

function requestDelete(rule: AdminAutomationRule): void {
  deleteTarget.value = rule
  deleteReason.value = ''
  deleteDialog.value = true
}

async function confirmDelete(): Promise<void> {
  if (deleteTarget.value === null || !deleteValid.value) return
  pending.value = true
  try {
    const ulid = deleteTarget.value.id
    await adminAutomationsApi.remove(ulid, deleteReason.value.trim())
    items.value = items.value.filter((rule) => rule.id !== ulid)
    deleteDialog.value = false
    deleteTarget.value = null
    notify('admin.automations.delete.success', 'success')
  } catch {
    notify('admin.automations.delete.failed', 'error')
  } finally {
    pending.value = false
  }
}

function replaceRow(rule: AdminAutomationRule): void {
  const idx = items.value.findIndex((row) => row.id === rule.id)
  if (idx !== -1) items.value[idx] = rule
}

function targetLabel(rule: AdminAutomationRule): string {
  return rule.attributes.target_agency_name ?? t('admin.automations.target.unknown')
}

function formatDate(value: string | null): string {
  if (value === null) return t('admin.automations.never')
  return new Date(value).toLocaleString()
}
</script>

<template>
  <section data-testid="admin-automations">
    <header class="d-flex align-center mb-4">
      <div>
        <h1 class="text-h5 ma-0">{{ t('admin.automations.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis ma-0">{{ t('admin.automations.subtitle') }}</p>
      </div>
      <v-spacer />
      <v-btn
        color="primary"
        variant="flat"
        prepend-icon="mdi-plus"
        data-testid="admin-automations-create"
        @click="openCreate"
      >
        {{ t('admin.automations.create') }}
      </v-btn>
    </header>

    <v-alert type="info" variant="tonal" class="mb-4" data-testid="admin-automations-flag-notice">
      {{ t('admin.automations.flag_notice') }}
    </v-alert>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-automations-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <v-card variant="outlined">
      <v-progress-linear v-if="loading" indeterminate />

      <v-table v-if="items.length > 0" data-testid="admin-automations-table">
        <thead>
          <tr>
            <th>{{ t('admin.automations.columns.name') }}</th>
            <th>{{ t('admin.automations.columns.event') }}</th>
            <th>{{ t('admin.automations.columns.action') }}</th>
            <th>{{ t('admin.automations.columns.target') }}</th>
            <th>{{ t('admin.automations.columns.lastFired') }}</th>
            <th class="text-right">{{ t('admin.automations.columns.fireCount') }}</th>
            <th>{{ t('admin.automations.columns.enabled') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="rule in items" :key="rule.id" :data-testid="`admin-automation-${rule.id}`">
            <td>{{ rule.attributes.name }}</td>
            <td>{{ eventLabel(rule.attributes.event) }}</td>
            <td>{{ actionLabel(rule.attributes.action) }}</td>
            <td>
              {{ targetLabel(rule) }}
              <v-chip
                v-if="rule.attributes.broken_reason"
                color="warning"
                size="x-small"
                class="ml-2"
                :data-testid="`admin-automation-broken-${rule.id}`"
              >
                {{ t(`admin.automations.broken.${rule.attributes.broken_reason}`) }}
              </v-chip>
            </td>
            <td>{{ formatDate(rule.attributes.last_fired_at) }}</td>
            <td class="text-right">{{ rule.attributes.fire_count }}</td>
            <td>
              <v-switch
                :model-value="rule.attributes.enabled"
                color="primary"
                hide-details
                density="compact"
                inset
                :disabled="pending"
                :data-testid="`admin-automation-switch-${rule.id}`"
                @click.prevent="requestToggle(rule)"
              />
            </td>
            <td class="text-right">
              <v-btn
                icon="mdi-pencil-outline"
                variant="text"
                size="small"
                :disabled="pending"
                :data-testid="`admin-automation-edit-${rule.id}`"
                @click="openEdit(rule)"
              />
              <v-btn
                icon="mdi-delete-outline"
                variant="text"
                size="small"
                :disabled="pending"
                :data-testid="`admin-automation-delete-${rule.id}`"
                @click="requestDelete(rule)"
              />
            </td>
          </tr>
        </tbody>
      </v-table>

      <v-card-text v-else-if="!loading" data-testid="admin-automations-empty">
        {{ t('admin.automations.empty') }}
      </v-card-text>
    </v-card>

    <!-- Create / edit -->
    <v-dialog v-model="formDialog" max-width="620" data-testid="admin-automation-form-dialog">
      <v-card>
        <v-card-title>
          {{
            editing === null
              ? t('admin.automations.form.createTitle')
              : t('admin.automations.form.editTitle')
          }}
        </v-card-title>
        <v-card-text>
          <v-text-field
            v-model="form.name"
            :label="t('admin.automations.form.name')"
            :hint="t('admin.automations.form.name_hint')"
            persistent-hint
            variant="outlined"
            class="mb-4"
            data-testid="admin-automation-form-name"
          />
          <v-select
            v-model="form.event"
            :items="eventOptions"
            :label="t('admin.automations.form.event')"
            variant="outlined"
            class="mb-4"
            data-testid="admin-automation-form-event"
          />
          <v-select
            v-model="form.action"
            :items="actionOptions"
            :label="t('admin.automations.form.action')"
            variant="outlined"
            class="mb-4"
            data-testid="admin-automation-form-action"
          />
          <v-autocomplete
            v-model="form.agencyId"
            :items="agencyOptions"
            :label="t('admin.automations.form.agency')"
            :hint="t('admin.automations.form.agency_hint')"
            persistent-hint
            variant="outlined"
            class="mb-4"
            data-testid="admin-automation-form-agency"
          />

          <v-alert
            v-if="existingForEvent.length > 0"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
            data-testid="admin-automation-form-existing"
          >
            {{ t('admin.automations.form.existing', { count: existingForEvent.length }) }}
          </v-alert>

          <v-textarea
            v-model="form.reason"
            :label="t('admin.automations.form.reason')"
            :hint="t('admin.automations.form.reason_hint', { count: MIN_REASON })"
            persistent-hint
            rows="2"
            variant="outlined"
            data-testid="admin-automation-form-reason"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="pending" @click="closeForm">
            {{ t('admin.automations.cancel') }}
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :disabled="!formValid || pending"
            :loading="pending"
            data-testid="admin-automation-form-confirm"
            @click="submitForm"
          >
            {{ t('admin.automations.save') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Toggle -->
    <v-dialog v-model="toggleDialog" max-width="520" data-testid="admin-automation-toggle-dialog">
      <v-card v-if="toggleTarget">
        <v-card-title>
          {{
            toggleTarget.next
              ? t('admin.automations.toggle.enableTitle', {
                  name: toggleTarget.rule.attributes.name,
                })
              : t('admin.automations.toggle.disableTitle', {
                  name: toggleTarget.rule.attributes.name,
                })
          }}
        </v-card-title>
        <v-card-text>
          <p class="text-body-2 mb-4">{{ t('admin.automations.toggle.description') }}</p>
          <v-textarea
            v-model="toggleReason"
            :label="t('admin.automations.form.reason')"
            :hint="t('admin.automations.form.reason_hint', { count: MIN_REASON })"
            persistent-hint
            rows="2"
            variant="outlined"
            data-testid="admin-automation-toggle-reason"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="pending" @click="toggleDialog = false">
            {{ t('admin.automations.cancel') }}
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :disabled="!toggleValid || pending"
            :loading="pending"
            data-testid="admin-automation-toggle-confirm"
            @click="confirmToggle"
          >
            {{ t('admin.automations.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete -->
    <v-dialog v-model="deleteDialog" max-width="520" data-testid="admin-automation-delete-dialog">
      <v-card v-if="deleteTarget">
        <v-card-title>
          {{ t('admin.automations.delete.title', { name: deleteTarget.attributes.name }) }}
        </v-card-title>
        <v-card-text>
          <p class="text-body-2 mb-4">{{ t('admin.automations.delete.description') }}</p>
          <v-textarea
            v-model="deleteReason"
            :label="t('admin.automations.form.reason')"
            :hint="t('admin.automations.form.reason_hint', { count: MIN_REASON })"
            persistent-hint
            rows="2"
            variant="outlined"
            data-testid="admin-automation-delete-reason"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="pending" @click="deleteDialog = false">
            {{ t('admin.automations.cancel') }}
          </v-btn>
          <v-btn
            color="error"
            variant="flat"
            :disabled="!deleteValid || pending"
            :loading="pending"
            data-testid="admin-automation-delete-confirm"
            @click="confirmDelete"
          >
            {{ t('admin.automations.delete.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar
      v-model="snackbar.show"
      :color="snackbar.color"
      data-testid="admin-automations-snackbar"
    >
      {{ t(snackbar.messageKey) }}
    </v-snackbar>
  </section>
</template>
