<script setup lang="ts">
/**
 * CreatorMasterAgreementSection — the profile page's "Master agreement"
 * section (AH-098).
 *
 * Collapsed by default (the closest existing precedent in this codebase —
 * there is no Vuetify `v-expansion-panel` usage anywhere — is the
 * roster/discover portfolio drawer's clickable-header + chevron +
 * `v-expand-transition` idiom; see `CreatorDetailPage.vue`'s
 * `portfolioExpanded` section, mirrored here).
 *
 * The header always shows the acceptance sentence (mirrored, not
 * verbatim-reused, from the wizard's `accepted_body` — that string also
 * carries "Continue to the next step.", which doesn't apply here) plus the
 * acceptance date WHEN STORED. `click_through_accepted_at` is only set for
 * the click-through path; a vendor-signed creator
 * (`has_signed_master_contract === true`) can have no timestamp at all
 * (`ProcessEsignWebhookJob` never sets `click_through_accepted_at`) — that
 * state renders the header with no date line, never a placeholder.
 *
 * Expanding reveals the CURRENT agreement text via the same
 * {@link MasterAgreementTerms} renderer the wizard's Step 8 uses — no
 * version comparison against what was actually accepted. That comparison
 * is not available: `CreatorResource` (the payload backing this page)
 * exposes only the acceptance boolean + timestamp, never
 * `contracts.version` or the accept-time `body_markdown` snapshot — so
 * there is no "you accepted version X" to report even if we wanted to.
 * Named as an honest limitation in the AH-098 log entry, not fixed here
 * (surfacing it would be a new API resource — out of this batch's scope).
 *
 * Hidden — not an empty state — when there is no acceptance on record at
 * all (neither flag set): closing that gap is a named follow-up, not
 * built (locked decision).
 */

import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import MasterAgreementTerms from '@/components/MasterAgreementTerms.vue'

const props = defineProps<{
  hasSignedMasterContract: boolean
  clickThroughAcceptedAt: string | null
}>()

const { t } = useI18n()

const isAccepted = computed(
  () => props.hasSignedMasterContract || props.clickThroughAcceptedAt !== null,
)

const formattedAcceptedDate = computed(() =>
  props.clickThroughAcceptedAt === null
    ? null
    : new Date(props.clickThroughAcceptedAt).toLocaleDateString(),
)

const expanded = ref(false)

function toggle(): void {
  expanded.value = !expanded.value
}
</script>

<template>
  <section
    v-if="isAccepted"
    class="creator-profile__section"
    data-testid="creator-profile-master-agreement"
  >
    <div
      class="master-agreement-section__header"
      role="button"
      tabindex="0"
      :aria-expanded="expanded"
      data-testid="master-agreement-toggle"
      @click="toggle"
      @keydown.enter.prevent="toggle"
      @keydown.space.prevent="toggle"
    >
      <div class="master-agreement-section__summary">
        <h2 class="text-h6 creator-profile__section-heading">
          {{ t('creator.ui.wizard.steps.contract.name') }}
        </h2>
        <p class="text-body-2" data-testid="master-agreement-accepted-header">
          {{ t('creator.ui.profile.master_agreement.accepted_header') }}
        </p>
        <p
          v-if="formattedAcceptedDate !== null"
          class="text-body-2 text-medium-emphasis"
          data-testid="master-agreement-accepted-date"
        >
          {{
            t('creator.ui.profile.master_agreement.accepted_on', { date: formattedAcceptedDate })
          }}
        </p>
      </div>
      <v-btn
        :icon="expanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
        variant="text"
        size="small"
        :aria-label="t('creator.ui.profile.master_agreement.toggle_label')"
        data-testid="master-agreement-toggle-icon"
        @click.stop="toggle"
      />
    </div>

    <!-- `v-if` (not `v-show`, unlike the roster portfolio precedent): the
         terms fetch is a real network call this component owns, so the
         common "never expanded" case should never issue it. Re-expanding
         re-fetches — cheap (array-cached render server-side) and it means
         the panel always shows the freshest current text on each open. -->
    <v-expand-transition>
      <div v-if="expanded" data-testid="master-agreement-panel">
        <MasterAgreementTerms :label="t('creator.ui.wizard.steps.contract.name')" />
      </div>
    </v-expand-transition>
  </section>
</template>

<style scoped>
.master-agreement-section__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  cursor: pointer;
}

.master-agreement-section__summary {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
</style>
