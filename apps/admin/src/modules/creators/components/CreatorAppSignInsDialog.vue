<script setup lang="ts">
/**
 * Sign-ins for one creator (AH-102b, Half B).
 *
 * "Sign-in", never "device": a row is one login of the creator app, not a
 * physical handset. Only `live` and `expired` rows exist — a sign-in that was
 * ended on purpose (logout, password reset, lockout) is deleted, so it has no
 * row to show; the list view reads that from the audit trail instead.
 *
 * The app version is "—" for a sign-in that never registered for push (the
 * version is only known from the push registration).
 */

import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError, formatDateTime } from '@catalyst/api-client'

import { adminCreatorAppApi, type AdminCreatorAppSignIn } from '../api/creatorApp.api'

const props = defineProps<{
  modelValue: boolean
  creatorUlid: string | null
  creatorName: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const { t, locale } = useI18n()

const rows = ref<AdminCreatorAppSignIn[]>([])
const total = ref(0)
const loading = ref(false)
const errorKey = ref<string | null>(null)
let requestSeq = 0

const open = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
})

// Deep link into the delivery log, pre-filtered to this creator.
// `creator_name` is display-only on the target page.
const deliveriesLocation = computed(() => ({
  name: 'app.operations.push-deliveries',
  query: {
    creator: props.creatorUlid ?? '',
    ...(props.creatorName !== null && props.creatorName !== ''
      ? { creator_name: props.creatorName }
      : {}),
  },
}))

async function load(ulid: string): Promise<void> {
  const seq = ++requestSeq
  loading.value = true
  errorKey.value = null
  rows.value = []
  try {
    const res = await adminCreatorAppApi.signIns(ulid)
    if (seq !== requestSeq) return
    rows.value = res.data
    total.value = res.meta.total
  } catch (error) {
    if (seq !== requestSeq) return
    errorKey.value =
      error instanceof ApiError ? error.code : 'admin.creators.app.sign_ins.load_failed'
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

watch(
  () => [props.modelValue, props.creatorUlid] as const,
  ([isOpen, ulid]) => {
    if (isOpen && ulid !== null) void load(ulid)
  },
  { immediate: true },
)

function when(iso: string | null): string {
  return iso === null ? '—' : formatDateTime(iso, locale.value)
}

function platformLabel(platform: AdminCreatorAppSignIn['platform']): string {
  if (platform === 'ios') return 'iOS'
  if (platform === 'android') return 'Android'
  return '—'
}
</script>

<template>
  <v-dialog v-model="open" max-width="860" scrollable>
    <v-card data-testid="admin-creator-app-signins">
      <v-card-title class="d-flex align-center justify-space-between">
        <span>
          {{ t('admin.creators.app.sign_ins.title') }}
          <span v-if="creatorName" class="text-medium-emphasis text-body-1">
            · {{ creatorName }}
          </span>
        </span>
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          :aria-label="t('admin.creators.app.sign_ins.close')"
          data-testid="admin-creator-app-signins-close"
          @click="open = false"
        />
      </v-card-title>

      <v-card-text>
        <p class="text-body-2 text-medium-emphasis mb-3">
          {{ t('admin.creators.app.sign_ins.note') }}
        </p>

        <v-alert
          v-if="errorKey"
          type="error"
          variant="tonal"
          class="mb-3"
          data-testid="admin-creator-app-signins-error"
        >
          {{ t(errorKey) }}
        </v-alert>

        <v-progress-linear v-if="loading" indeterminate class="mb-3" />

        <v-table density="compact" data-testid="admin-creator-app-signins-table">
          <thead>
            <tr>
              <th>{{ t('admin.creators.app.sign_ins.columns.platform') }}</th>
              <th>{{ t('admin.creators.app.sign_ins.columns.signed_in') }}</th>
              <th>{{ t('admin.creators.app.sign_ins.columns.last_seen') }}</th>
              <th>{{ t('admin.creators.app.sign_ins.columns.state') }}</th>
              <th>{{ t('admin.creators.app.sign_ins.columns.app_version') }}</th>
              <th>{{ t('admin.creators.app.sign_ins.columns.push') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && rows.length === 0 && !errorKey">
              <td
                colspan="6"
                class="text-medium-emphasis"
                data-testid="admin-creator-app-signins-empty"
              >
                {{ t('admin.creators.app.sign_ins.empty') }}
              </td>
            </tr>
            <tr
              v-for="(row, index) in rows"
              :key="index"
              :data-testid="`admin-creator-app-signin-${index}`"
            >
              <td>{{ platformLabel(row.platform) }}</td>
              <td>{{ when(row.signed_in_at) }}</td>
              <td>{{ when(row.last_seen_at) }}</td>
              <td>
                <v-chip
                  size="x-small"
                  variant="tonal"
                  :color="row.state === 'live' ? 'success' : undefined"
                >
                  {{ t(`admin.creators.app.sign_ins.state.${row.state}`) }}
                </v-chip>
              </td>
              <td :data-testid="`admin-creator-app-signin-version-${index}`">
                {{ row.app_version ?? '—' }}
              </td>
              <td>{{ t(`admin.creators.app.filters.push.${row.push_state}`) }}</td>
            </tr>
          </tbody>
        </v-table>

        <p
          v-if="rows.length > 0"
          class="text-caption text-medium-emphasis mt-2"
          data-testid="admin-creator-app-signins-showing"
        >
          {{ t('admin.creators.app.sign_ins.showing', { shown: rows.length, total }) }}
        </p>
      </v-card-text>

      <v-card-actions>
        <v-btn
          variant="text"
          color="primary"
          prepend-icon="mdi-bell-outline"
          :to="deliveriesLocation"
          data-testid="admin-creator-app-signins-deliveries"
        >
          {{ t('admin.creators.app.sign_ins.view_deliveries') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
