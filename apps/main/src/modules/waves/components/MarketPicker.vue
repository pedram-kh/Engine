<script setup lang="ts">
/**
 * PL-1.1 — the Markets field of a wave: chips for the picked countries and an "Add market" menu over
 * `COUNTRY_OPTIONS`. Values are uppercase ISO codes, in the order picked. At most `MAX_MARKETS`.
 */
import { COUNTRY_OPTIONS, labelForCountryCode } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { MAX_MARKETS } from '../constants'

const props = defineProps<{
  modelValue: string[]
  errorMessage?: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()

const { t } = useI18n()

const menu = ref(false)
const search = ref('')

const available = computed(() => {
  const needle = search.value.trim().toLowerCase()
  return COUNTRY_OPTIONS.filter((c) => !props.modelValue.includes(c.code)).filter(
    (c) =>
      needle === '' || c.label.toLowerCase().includes(needle) || c.code.toLowerCase() === needle,
  )
})

const atLimit = computed(() => props.modelValue.length >= MAX_MARKETS)

function add(code: string): void {
  if (props.modelValue.includes(code) || atLimit.value) return
  emit('update:modelValue', [...props.modelValue, code])
  search.value = ''
  menu.value = false
}

function remove(code: string): void {
  emit(
    'update:modelValue',
    props.modelValue.filter((c) => c !== code),
  )
}
</script>

<template>
  <div class="market-picker" data-test="market-picker">
    <div class="d-flex flex-wrap ga-2 align-center">
      <v-chip
        v-for="code in modelValue"
        :key="code"
        size="small"
        closable
        :close-label="t('app.waves.dialog.removeMarket', { market: labelForCountryCode(code) })"
        :data-test="`market-chip-${code}`"
        @click:close="remove(code)"
      >
        {{ labelForCountryCode(code) }}
      </v-chip>

      <v-menu v-model="menu" :close-on-content-click="false" :disabled="atLimit">
        <template #activator="{ props: activatorProps }">
          <v-chip
            v-bind="activatorProps"
            size="small"
            variant="outlined"
            prepend-icon="mdi-plus"
            :disabled="atLimit"
            data-test="market-add"
          >
            {{ t('app.waves.dialog.addMarket') }}
          </v-chip>
        </template>
        <v-card min-width="260" max-width="320">
          <v-text-field
            v-model="search"
            density="compact"
            variant="outlined"
            hide-details
            autofocus
            class="ma-2"
            :placeholder="t('app.waves.dialog.searchMarket')"
            data-test="market-search"
          />
          <v-list density="compact" max-height="260" class="overflow-y-auto">
            <v-list-item
              v-for="c in available"
              :key="c.code"
              :title="c.label"
              :data-test="`market-option-${c.code}`"
              @click="add(c.code)"
            />
            <v-list-item
              v-if="available.length === 0"
              :title="t('app.waves.dialog.noMarketMatch')"
              disabled
              data-test="market-none"
            />
          </v-list>
        </v-card>
      </v-menu>
    </div>
    <p v-if="errorMessage" class="text-caption text-error mt-1 mb-0" data-test="market-error">
      {{ errorMessage }}
    </p>
  </div>
</template>
