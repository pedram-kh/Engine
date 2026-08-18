<script setup lang="ts">
/**
 * CreatorProfileDialog (AH-080) — the `v-dialog` shell around
 * `CreatorProfileContent`, for the two mount contexts that open the profile
 * as a standalone modal (campaign Creators-tab rows, application
 * cards/rows). The board card drawer's Profile tab renders
 * `CreatorProfileContent` directly (no nested dialog — see that component's
 * docblock).
 *
 * `CreatorProfileContent` is mounted only while `modelValue` is true (a plain
 * `v-if`, keyed on the creator), so every open is a fresh load — the same
 * "opens are transient" behaviour a page navigation to `CreatorDetailPage`
 * would give, just without leaving the current screen (D2a/D2c).
 */

import { useI18n } from 'vue-i18n'

import CreatorProfileContent from './CreatorProfileContent.vue'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    agencyId: string
    creatorUlid: string
    /** See `CreatorProfileContent`'s prop of the same name. */
    assumeFull?: boolean
  }>(),
  { assumeFull: false },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const { t } = useI18n()

function close(): void {
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="720"
    scrollable
    data-test="creator-profile-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="d-flex align-center">
        {{ t('app.roster.detail.sections.profile') }}
        <v-spacer />
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          data-test="creator-profile-dialog-close"
          @click="close"
        />
      </v-card-title>
      <v-divider />

      <v-card-text>
        <CreatorProfileContent
          v-if="modelValue"
          :key="creatorUlid"
          :agency-id="agencyId"
          :creator-ulid="creatorUlid"
          :assume-full="props.assumeFull"
        />
      </v-card-text>
    </v-card>
  </v-dialog>
</template>
