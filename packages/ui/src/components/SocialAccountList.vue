<script setup lang="ts">
/**
 * SocialAccountList — render the creator's connected social
 * accounts as a list of [icon, handle, external link] rows.
 *
 * Sprint 3 Chunk 3 sub-step 5 (Decision C1: display-shared, form-main).
 *
 * Each account is an `{ platform, handle, profileUrl, platformLabel }`
 * record. The consumer is responsible for translating the platform
 * key (e.g. `instagram`) to a localized label and passing it via
 * the `platformLabel` field so this package stays i18n-free.
 *
 * Platform → MDI icon mapping is built-in (Instagram, TikTok,
 * YouTube — the three platforms shipped in Sprint 3 Chunk 1's
 * SocialPlatform enum). Unknown platforms render a generic
 * `mdi-link` icon as a safe default.
 *
 * a11y (F2=b): each row is a `<li>` inside a `<ul>` so screen
 * readers announce list semantics. The external link uses
 * `rel="noopener nofollow"` and `target="_blank"`.
 */

interface SocialAccount {
  platform: string
  handle: string
  profileUrl: string
  platformLabel: string
}

interface Props {
  accounts: ReadonlyArray<SocialAccount>
  emptyLabel?: string
}

const props = withDefaults(defineProps<Props>(), {
  emptyLabel: '—',
})

const PLATFORM_ICONS: Record<string, string> = {
  instagram: 'mdi-instagram',
  tiktok: 'mdi-music-note',
  youtube: 'mdi-youtube',
}

function iconFor(platform: string): string {
  return PLATFORM_ICONS[platform] ?? 'mdi-link'
}
</script>

<template>
  <ul
    v-if="props.accounts.length > 0"
    class="social-account-list"
    data-testid="social-account-list"
  >
    <li
      v-for="account in props.accounts"
      :key="`${account.platform}:${account.handle}`"
      class="social-account-list__row"
      :data-testid="`social-account-row-${account.platform}`"
    >
      <v-icon :icon="iconFor(account.platform)" size="20" aria-hidden="true" />
      <span class="social-account-list__platform">{{ account.platformLabel }}</span>
      <a
        class="social-account-list__handle"
        :href="account.profileUrl"
        target="_blank"
        rel="noopener nofollow"
      >
        {{ account.handle }}
      </a>
    </li>
  </ul>
  <span v-else class="social-account-list--empty" data-testid="social-account-list-empty">
    {{ props.emptyLabel }}
  </span>
</template>

<style scoped>
.social-account-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.social-account-list__row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.9375rem;
}

.social-account-list__platform {
  font-weight: 500;
  min-width: 96px;
}

.social-account-list__handle {
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
}

.social-account-list__handle:hover,
.social-account-list__handle:focus {
  text-decoration: underline;
}

.social-account-list--empty {
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
