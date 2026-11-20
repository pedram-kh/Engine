<script setup lang="ts">
/**
 * PL-2.1 — the creative angles of one persona: a pill in the USP's colour with the USP title, the explanation, a
 * small "Manually added", and a menu (Edit, Remove). "Add angle from USPs" opens a list of the wave's USPs: those
 * this persona already has an angle on are grey with "Linked" and cannot be chosen; the others show "+". Choosing
 * one opens an inline row (one text field, Add and Cancel). With no USPs the list says to add them in step 1.
 *
 * One angle per USP per persona (the database holds the same rule). Every string is drawn with interpolation.
 */
import type {
  WavePersonaResource,
  WaveAngleResource,
  WaveUspResource,
  StrategyLimits,
} from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { brandColorVar } from '@/modules/agency/composables/brandColor'
import { strategyApi, type WaveScope } from '../api/strategy.api'
import { MENU_PROPS } from '../constants'
import { fieldErrorsOf, isGone, strategyErrorMessage } from '../strategyErrors'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

const props = defineProps<{
  persona: WavePersonaResource
  usps: WaveUspResource[]
  scope: WaveScope
  limits: StrategyLimits
  brandId: string
  waveId: string
}>()

const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()

const angles = computed(() => props.persona.attributes.angles)
const linkedIds = computed(() => new Set(angles.value.map((a) => a.attributes.usp)))

const listOpen = ref(false)
/** The USP being linked, or the angle being edited; at most one inline row is open on a card. */
const adding = ref<WaveUspResource | null>(null)
const editing = ref<WaveAngleResource | null>(null)
const text = ref('')
const saving = ref(false)
const error = ref<string | null>(null)

const removing = ref<WaveAngleResource | null>(null)
const removeOpen = ref(false)
const removeBusy = ref(false)
/** Removing a hand-written angle on a pair whose cell is Priority or Support: the synced angle takes its place. */
const removeSyncs = computed(() => {
  const usp = removing.value?.attributes.usp
  return props.persona.attributes.matrix_cells.some(
    (c) => c.usp === usp && (c.level === 'priority' || c.level === 'support'),
  )
})
const removeError = ref<string | null>(null)

function startAdd(usp: WaveUspResource): void {
  listOpen.value = false
  editing.value = null
  adding.value = usp
  text.value = ''
  error.value = null
}
function startEdit(angle: WaveAngleResource): void {
  adding.value = null
  editing.value = angle
  text.value = angle.attributes.explanation
  error.value = null
}
function cancelRow(): void {
  adding.value = null
  editing.value = null
  error.value = null
}

async function saveRow(): Promise<void> {
  const value = text.value.trim()
  if (value === '') {
    error.value = t('app.strategy.persona.validation.angleRequired')
    return
  }
  if (Array.from(value).length > props.limits.angle_explanation) {
    error.value = t('app.strategy.validation.tooLong', { max: props.limits.angle_explanation })
    return
  }
  saving.value = true
  error.value = null
  try {
    if (adding.value) {
      await strategyApi.addAngle(props.scope, props.persona.id, adding.value.id, value)
    } else if (editing.value) {
      await strategyApi.updateAngle(props.scope, props.persona.id, editing.value.id, value)
    }
    cancelRow()
    emit('changed')
  } catch (err) {
    error.value = fieldErrorsOf(err)['explanation'] ?? strategyErrorMessage(err, t)
    // The card, the USP or the angle is gone, or someone linked it first: the card shows the real state next.
    if (isGone(err)) emit('changed')
  } finally {
    saving.value = false
  }
}

function askRemove(angle: WaveAngleResource): void {
  removing.value = angle
  removeError.value = null
  removeOpen.value = true
}

async function confirmRemove(): Promise<void> {
  if (!removing.value) return
  removeBusy.value = true
  removeError.value = null
  try {
    await strategyApi.removeAngle(props.scope, props.persona.id, removing.value.id)
    removeOpen.value = false
    emit('changed')
  } catch (err) {
    if (isGone(err)) {
      removeOpen.value = false
      emit('changed')
    } else {
      removeError.value = strategyErrorMessage(err, t)
    }
  } finally {
    removeBusy.value = false
  }
}
</script>

<template>
  <section class="angles" data-test="angles">
    <h4 class="angles__label">
      <v-icon icon="mdi-key-outline" size="15" class="angles__label-icon" aria-hidden="true" />
      {{ t('app.strategy.persona.angles') }}
    </h4>

    <ul v-if="angles.length > 0" class="angles__list">
      <li
        v-for="angle in angles"
        :key="angle.id"
        class="angle"
        :style="{ '--usp-color': brandColorVar(angle.attributes.color_key) }"
        data-test="angle"
      >
        <template v-if="editing && editing.id === angle.id">
          <span class="angle__pill">
            <span class="angle__pill-dot" aria-hidden="true" />
            {{ angle.attributes.usp_title }}
          </span>
          <div class="angle__form" data-test="angle-edit-row">
            <v-text-field
              v-model="text"
              :aria-label="t('app.strategy.persona.angleText')"
              variant="outlined"
              density="compact"
              :counter="limits.angle_explanation"
              hide-details="auto"
              :error-messages="error ? [error] : []"
              data-test="angle-text"
              @keydown.enter.prevent="saveRow"
            />
            <div class="d-flex ga-2">
              <v-btn
                size="small"
                color="primary"
                variant="flat"
                :loading="saving"
                data-test="angle-save"
                @click="saveRow"
              >
                {{ t('app.strategy.save') }}
              </v-btn>
              <v-btn
                size="small"
                variant="text"
                :disabled="saving"
                data-test="angle-cancel"
                @click="cancelRow"
              >
                {{ t('app.strategy.cancel') }}
              </v-btn>
            </div>
          </div>
        </template>
        <template v-else>
          <span class="angle__pill" data-test="angle-pill">
            <span class="angle__pill-dot" aria-hidden="true" />
            {{ angle.attributes.usp_title }}
          </span>
          <div class="angle__body">
            <p class="angle__text" data-test="angle-explanation">
              {{ angle.attributes.explanation }}
            </p>
            <span
              v-if="angle.attributes.read_only"
              class="angle__origin angle__origin--synced"
              data-test="angle-origin"
              >{{ t('app.strategy.persona.syncedFromMatrix') }}</span
            >
            <span v-else class="angle__origin" data-test="angle-origin">{{
              t('app.strategy.persona.addedByHand')
            }}</span>
            <router-link
              v-if="angle.attributes.read_only"
              class="angle__matrix-link"
              :to="{
                name: 'strategy.matrix',
                params: { ulid: brandId, waveUlid: waveId },
                query: { market: angle.attributes.market ?? persona.attributes.market },
              }"
              data-test="angle-matrix-link"
            >
              {{ t('app.strategy.persona.changeInMatrix') }}
            </router-link>
          </div>
          <v-menu v-if="!angle.attributes.read_only" v-bind="MENU_PROPS" location="bottom end">
            <template #activator="{ props: act }">
              <v-btn
                v-bind="act"
                icon="mdi-dots-horizontal"
                variant="text"
                size="x-small"
                class="angle__menu"
                :aria-label="
                  t('app.strategy.persona.angleMenu', { title: angle.attributes.usp_title })
                "
                data-test="angle-menu-btn"
              />
            </template>
            <v-list density="compact">
              <v-list-item data-test="angle-menu-edit" @click="startEdit(angle)">
                <v-list-item-title>{{ t('app.strategy.persona.angleEdit') }}</v-list-item-title>
              </v-list-item>
              <v-list-item data-test="angle-menu-remove" @click="askRemove(angle)">
                <v-list-item-title>{{ t('app.strategy.persona.angleRemove') }}</v-list-item-title>
              </v-list-item>
            </v-list>
          </v-menu>
        </template>
      </li>
    </ul>

    <div
      v-if="adding"
      class="angle"
      :style="{ '--usp-color': brandColorVar(adding.attributes.color_key) }"
      data-test="angle-add-row"
    >
      <span class="angle__pill">
        <span class="angle__pill-dot" aria-hidden="true" />
        {{ adding.attributes.title }}
      </span>
      <div class="angle__form">
        <v-text-field
          v-model="text"
          :aria-label="t('app.strategy.persona.angleText')"
          :placeholder="t('app.strategy.persona.anglePlaceholder')"
          variant="outlined"
          density="compact"
          :counter="limits.angle_explanation"
          hide-details="auto"
          :error-messages="error ? [error] : []"
          data-test="angle-text"
          @keydown.enter.prevent="saveRow"
        />
        <div class="d-flex ga-2">
          <v-btn
            size="small"
            color="primary"
            variant="flat"
            :loading="saving"
            data-test="angle-add"
            @click="saveRow"
          >
            {{ t('app.strategy.persona.angleAdd') }}
          </v-btn>
          <v-btn
            size="small"
            variant="text"
            :disabled="saving"
            data-test="angle-cancel"
            @click="cancelRow"
          >
            {{ t('app.strategy.cancel') }}
          </v-btn>
        </div>
      </div>
    </div>

    <v-menu v-model="listOpen" v-bind="MENU_PROPS" location="bottom start">
      <template #activator="{ props: act }">
        <v-btn
          v-bind="act"
          variant="outlined"
          size="small"
          class="angles__add"
          prepend-icon="mdi-plus"
          append-icon="mdi-chevron-down"
          data-test="angle-link-btn"
        >
          {{ t('app.strategy.persona.addAngle') }}
        </v-btn>
      </template>
      <v-list
        density="compact"
        min-width="300"
        class="angle-list"
        :aria-label="t('app.strategy.persona.linkAUsp')"
        data-test="angle-usp-list"
      >
        <div class="angle-list__head" aria-hidden="true">
          {{ t('app.strategy.persona.linkAUsp') }}
        </div>
        <v-list-item v-if="usps.length === 0" class="angle-list__item" data-test="angle-no-usps">
          <v-list-item-title class="text-wrap">{{
            t('app.strategy.persona.noUsps')
          }}</v-list-item-title>
          <v-list-item-subtitle>
            <router-link
              :to="{ name: 'strategy.usps', params: { ulid: brandId, waveUlid: waveId } }"
              data-test="angle-no-usps-link"
            >
              {{ t('app.strategy.persona.goToUsps') }}
            </router-link>
          </v-list-item-subtitle>
        </v-list-item>
        <v-list-item
          v-for="usp in usps"
          :key="usp.id"
          class="angle-list__item"
          :class="{ 'angle-list__item--linked': linkedIds.has(usp.id) }"
          :disabled="linkedIds.has(usp.id)"
          :data-test="linkedIds.has(usp.id) ? 'angle-usp-linked' : 'angle-usp-option'"
          @click="startAdd(usp)"
        >
          <template #prepend>
            <span
              class="angle__dot"
              :style="{ '--usp-color': brandColorVar(usp.attributes.color_key) }"
            />
          </template>
          <v-list-item-title class="text-wrap">{{ usp.attributes.title }}</v-list-item-title>
          <template #append>
            <span v-if="linkedIds.has(usp.id)" class="angle-list__linked">{{
              t('app.strategy.persona.linked')
            }}</span>
            <v-icon v-else icon="mdi-plus" size="13" class="angle-list__plus" aria-hidden="true" />
          </template>
        </v-list-item>
      </v-list>
    </v-menu>

    <StrategyConfirmDialog
      v-model="removeOpen"
      test-id="angle-remove-dialog"
      :title="t('app.strategy.persona.removeAngleTitle')"
      :body="
        removeSyncs
          ? t('app.strategy.persona.removeAngleBodySyncs', {
              usp: removing?.attributes.usp_title ?? '',
            })
          : t('app.strategy.persona.removeAngleBody', { usp: removing?.attributes.usp_title ?? '' })
      "
      :confirm-label="t('app.strategy.persona.angleRemove')"
      :busy="removeBusy"
      :error="removeError"
      @confirm="confirmRemove"
    />
  </section>
</template>

<style scoped>
/*
 * Values from frame 13:828 (the "Creative angles that unlock" block of SK1 and the open "Link a USP" list), through
 * the agency theme tokens. The USP colour is `--usp-color`; the frame's fills are its 8% tint, its pill 20%, its border 30%.
 */
.angles {
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: stretch;
  padding-top: 14px;
  border-top: 1px solid rgb(var(--v-theme-border-color));
}
.angles__label {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.angles__label-icon {
  color: rgb(var(--v-theme-primary));
}
.angles__list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.angle {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 10px;
  padding: 8px 10px;
  border: 1px solid color-mix(in srgb, var(--usp-color) 30%, transparent);
  border-radius: 8px;
  background: color-mix(in srgb, var(--usp-color) 8%, transparent);
}
.angle__pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 2px 8px;
  border-radius: 9999px;
  font-size: 12px;
  font-weight: 600;
  line-height: normal;
  color: var(--usp-color);
  background: color-mix(in srgb, var(--usp-color) 20%, transparent);
  overflow-wrap: anywhere;
}
.angle__pill-dot {
  flex: 0 0 auto;
  width: 6px;
  height: 6px;
  border-radius: 9999px;
  background: var(--usp-color);
}
.angle__body {
  flex: 1 1 140px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.angle__text {
  margin: 0;
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
  white-space: pre-line;
  overflow-wrap: anywhere;
}
.angle__origin {
  font-size: 11px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary));
}
.angle__menu {
  flex: 0 0 auto;
  color: rgb(var(--v-theme-text-tertiary));
}
.angle__form {
  flex: 1 1 200px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.angle__dot {
  width: 8px;
  height: 8px;
  margin-right: 8px;
  border-radius: 9999px;
  background: var(--usp-color);
  display: inline-block;
}
/*
 * "Add angle from USPs": a dashed outline, 12px medium, plus and chevron icons of 13px. The frame draws it with no
 * fill and the grey text; the agency theme's secondary button has a surface fill, the strong border and the
 * on-surface text with a more specific selector than any scoped one, hence the !important.
 */
.angles .angles__add {
  align-self: flex-start;
  height: auto;
  min-height: 0;
  padding: 7px 10px;
  border: 1px dashed rgb(var(--v-theme-border-color)) !important;
  border-radius: 8px;
  background-color: transparent !important;
  font-size: 12px;
  font-weight: 500;
  letter-spacing: normal;
  text-transform: none;
  color: rgb(var(--v-theme-on-surface-variant)) !important;
}
.angles .angles__add :deep(.v-btn__prepend),
.angles .angles__add :deep(.v-btn__append) {
  margin: 0;
  font-size: 13px;
}
.angles .angles__add :deep(.v-btn__prepend) {
  margin-inline-end: 6px;
}
.angles .angles__add :deep(.v-btn__append) {
  margin-inline-start: 6px;
}
/* The open list: 6px padding, 2px between rows, a 10px upper-case grey head, rows of 8px 10px with a 6px radius. */
.angle-list {
  padding: 6px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.angle-list__head {
  padding: 6px 8px;
  font-size: 10px;
  font-weight: 600;
  line-height: normal;
  letter-spacing: 0.6px;
  text-transform: uppercase;
  color: rgb(var(--v-theme-text-tertiary));
}
.angle-list .angle-list__item {
  min-height: 32px;
  padding: 8px 10px;
  border-radius: 6px;
}
.angle-list .angle-list__item:hover {
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 8%, transparent);
}
.angle-list .angle-list__item :deep(.v-list-item-title) {
  font-size: 13px;
  font-weight: 400;
  line-height: 16px;
  color: rgb(var(--v-theme-on-surface));
}
.angle-list .angle-list__item--linked :deep(.v-list-item-title) {
  color: rgb(var(--v-theme-on-surface-variant));
}
.angle-list__linked {
  font-size: 11px;
  color: rgb(var(--v-theme-text-tertiary));
}
.angle-list__plus {
  color: rgb(var(--v-theme-primary));
}

.angle__origin--synced {
  color: rgb(var(--v-theme-on-surface-variant));
}
.angle__matrix-link {
  display: inline-block;
  margin-top: 2px;
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-primary-text, var(--v-theme-primary)));
}
</style>
