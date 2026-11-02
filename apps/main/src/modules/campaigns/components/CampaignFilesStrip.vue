<script setup lang="ts">
/**
 * `CampaignFilesStrip` — a compact, read-only list of a campaign's attached files (AH-116, item 6).
 *
 * Two placements, one component:
 *   - `overview`: the Campaign detail right panel, under "At a glance". Lists EVERY attached file;
 *     one the creators cannot see is tagged "Internal" (an operator choosing what to send needs to
 *     see the difference). Ends with a "View all files" link to the Attachments tab.
 *   - `chat`: pinned at the top of the campaign chat. Lists ONLY the files creators can see, with
 *     no tag: it answers "what did the creator receive" while you are writing to them, and an
 *     internal file has no business next to that conversation.
 *
 * It draws nothing when there is nothing to show, so a campaign without files has no empty box.
 * Each file is a download link on its signed URL, in a new tab. A file with no URL (a non-S3 disk
 * mints none) is drawn as plain text, never as a dead link. There is no upload, toggle or detach:
 * those stay in the Attachments tab.
 */

import { formatFileSize } from '@catalyst/api-client'
import type { CampaignAttachmentResource } from '@catalyst/api-client'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

/** More than this many files collapses into "+N more". */
const VISIBLE_CHIPS = 4

const props = defineProps<{
  files: CampaignAttachmentResource[]
  mode: 'overview' | 'chat'
}>()

const emit = defineEmits<{
  'view-all': []
}>()

const { t } = useI18n()

const listed = computed(() =>
  props.mode === 'chat'
    ? props.files.filter((file) => file.attributes.visible_to_creators)
    : props.files,
)
const shown = computed(() => listed.value.slice(0, VISIBLE_CHIPS))
const hiddenCount = computed(() => listed.value.length - shown.value.length)
</script>

<template>
  <section
    v-if="listed.length > 0"
    class="agency-files"
    :class="`agency-files--${mode}`"
    :data-test="`campaign-files-${mode}`"
  >
    <h2 class="agency-files__title">{{ t('app.campaigns.detail.files.title') }}</h2>

    <ul class="agency-files__list">
      <li v-for="file in shown" :key="file.id" :data-test="`campaign-file-${file.id}`">
        <component
          :is="file.attributes.download_url ? 'a' : 'span'"
          class="agency-file-chip"
          v-bind="
            file.attributes.download_url
              ? {
                  href: file.attributes.download_url,
                  target: '_blank',
                  rel: 'noopener noreferrer',
                  'aria-label': t('app.campaigns.detail.files.download', {
                    name: file.attributes.original_filename,
                  }),
                }
              : {}
          "
        >
          <v-icon icon="mdi-file-outline" size="16" aria-hidden="true" />
          <span class="agency-file-chip__name">{{ file.attributes.original_filename }}</span>
          <span class="agency-file-chip__size">{{
            formatFileSize(file.attributes.size_bytes)
          }}</span>
          <span
            v-if="mode === 'overview' && !file.attributes.visible_to_creators"
            class="agency-file-chip__tag"
            :data-test="`campaign-file-internal-${file.id}`"
          >
            {{ t('app.campaigns.detail.files.internal') }}
          </span>
        </component>
      </li>
    </ul>

    <div v-if="hiddenCount > 0 || mode === 'overview'" class="agency-files__foot">
      <span v-if="hiddenCount > 0" class="agency-files__more" data-test="campaign-files-more">
        {{ t('app.campaigns.detail.files.more', { count: hiddenCount }) }}
      </span>
      <button
        v-if="mode === 'overview'"
        type="button"
        class="agency-files__all"
        data-test="campaign-files-view-all"
        @click="emit('view-all')"
      >
        {{ t('app.campaigns.detail.files.viewAll') }}
      </button>
    </div>
  </section>
</template>
