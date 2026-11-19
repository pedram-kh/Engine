<script setup lang="ts">
/**
 * PL-2.1 — the creative angles of one persona: a pill in the USP's colour with the USP title, the explanation, a
 * small "Added by hand", and a menu (Edit, Remove). "Add angle from USPs" opens a list of the wave's USPs: those
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
    <h4 class="angles__label">{{ t('app.strategy.persona.angles') }}</h4>

    <ul v-if="angles.length > 0" class="angles__list">
      <li v-for="angle in angles" :key="angle.id" class="angle" data-test="angle">
        <template v-if="editing && editing.id === angle.id">
          <span
            class="angle__pill"
            :style="{ '--usp-color': brandColorVar(angle.attributes.color_key) }"
          >
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
          <div class="angle__head">
            <span
              class="angle__pill"
              :style="{ '--usp-color': brandColorVar(angle.attributes.color_key) }"
              data-test="angle-pill"
            >
              {{ angle.attributes.usp_title }}
            </span>
            <span class="angle__origin" data-test="angle-origin">{{
              t('app.strategy.persona.addedByHand')
            }}</span>
            <v-menu v-bind="MENU_PROPS" location="bottom end">
              <template #activator="{ props: act }">
                <v-btn
                  v-bind="act"
                  icon="mdi-dots-vertical"
                  variant="text"
                  size="x-small"
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
          </div>
          <p class="angle__text" data-test="angle-explanation">
            {{ angle.attributes.explanation }}
          </p>
        </template>
      </li>
    </ul>

    <div v-if="adding" class="angle" data-test="angle-add-row">
      <span
        class="angle__pill"
        :style="{ '--usp-color': brandColorVar(adding.attributes.color_key) }"
      >
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
          variant="text"
          size="small"
          prepend-icon="mdi-link-variant-plus"
          data-test="angle-link-btn"
        >
          {{ t('app.strategy.persona.addAngle') }}
        </v-btn>
      </template>
      <v-list density="compact" min-width="260" data-test="angle-usp-list">
        <v-list-item v-if="usps.length === 0" data-test="angle-no-usps">
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
            <span v-if="linkedIds.has(usp.id)" class="text-caption">{{
              t('app.strategy.persona.linked')
            }}</span>
            <v-icon v-else icon="mdi-plus" size="small" aria-hidden="true" />
          </template>
        </v-list-item>
      </v-list>
    </v-menu>

    <StrategyConfirmDialog
      v-model="removeOpen"
      test-id="angle-remove-dialog"
      :title="t('app.strategy.persona.removeAngleTitle')"
      :body="
        t('app.strategy.persona.removeAngleBody', { usp: removing?.attributes.usp_title ?? '' })
      "
      :confirm-label="t('app.strategy.persona.angleRemove')"
      :busy="removeBusy"
      :error="removeError"
      @confirm="confirmRemove"
    />
  </section>
</template>

<style scoped>
.angles {
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: flex-start;
}
.angles__label {
  margin: 0;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.65);
}
.angles__list {
  list-style: none;
  margin: 0;
  padding: 0;
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.angle {
  display: flex;
  flex-direction: column;
  gap: 4px;
  width: 100%;
}
.angle__head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.angle__pill {
  display: inline-block;
  align-self: flex-start;
  max-width: 100%;
  padding: 2px 10px;
  border-radius: 9999px;
  font-size: 12px;
  font-weight: 600;
  overflow-wrap: anywhere;
  border: 1px solid var(--usp-color);
  background: color-mix(in srgb, var(--usp-color) 18%, transparent);
}
.angle__origin {
  font-size: 11px;
  color: rgba(var(--v-theme-on-surface), 0.6);
  flex: 1 1 auto;
}
.angle__text {
  margin: 0;
  font-size: 13px;
  white-space: pre-line;
  overflow-wrap: anywhere;
}
.angle__form {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.angle__dot {
  width: 10px;
  height: 10px;
  margin-right: 10px;
  border-radius: 9999px;
  background: var(--usp-color);
  display: inline-block;
}
</style>
