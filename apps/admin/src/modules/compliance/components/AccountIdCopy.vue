<script setup lang="ts">
/**
 * An account's id (the user ulid) in a monospace chip with a copy button (AH-104c C3 walk).
 *
 * The Erase dialog asks the admin to TYPE this id to confirm, so an admin has to be able to read
 * it for a real creator: it is shown on every queue row and in the dialog. The id is not personal
 * data (it is the ledger's subject key, and the Erased tab already shows it). The copy button
 * makes reading it easy; it does not replace the typed confirmation, which the dialog still checks
 * on its own.
 */
import { onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  ulid: string
  /** Prefix for the test ids: `<testid>` on the id, `<testid>-copy` on the button. */
  testid: string
}>()

const { t } = useI18n()

const FEEDBACK_MS = 2000
const copied = ref(false)
let timer: ReturnType<typeof setTimeout> | null = null

async function copy(): Promise<void> {
  try {
    // No clipboard (an insecure origin, an old browser) or a refusal: nothing was copied, so say nothing.
    await navigator.clipboard.writeText(props.ulid)
  } catch {
    return
  }
  copied.value = true
  if (timer !== null) clearTimeout(timer)
  timer = setTimeout(() => {
    copied.value = false
    timer = null
  }, FEEDBACK_MS)
}

onBeforeUnmount(() => {
  if (timer !== null) clearTimeout(timer)
})
</script>

<template>
  <span class="account-id">
    <code class="account-id__value" :data-testid="testid">{{ ulid }}</code>
    <v-btn
      :icon="copied ? 'mdi-check' : 'mdi-content-copy'"
      size="x-small"
      variant="text"
      density="comfortable"
      :aria-label="t('admin.compliance.erasures.id.copy')"
      :title="
        copied ? t('admin.compliance.erasures.id.copied') : t('admin.compliance.erasures.id.copy')
      "
      :data-testid="`${testid}-copy`"
      @click="copy"
    />
    <span v-if="copied" class="text-caption" role="status" :data-testid="`${testid}-copied`">
      {{ t('admin.compliance.erasures.id.copied') }}
    </span>
  </span>
</template>

<style scoped>
.account-id {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.account-id__value {
  font-size: 0.8rem;
  user-select: all;
  word-break: break-all;
}
</style>
