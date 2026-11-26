/**
 * PL-3.2a — every autosaving block of the open script registers here, so the editor can say what is unsaved, flush all of it
 * before the person leaves or switches script, and show one chip for the script.
 */
import {
  computed,
  inject,
  onBeforeUnmount,
  provide,
  shallowReactive,
  type ComputedRef,
  type InjectionKey,
  type Ref,
} from 'vue'

import { isUnsaved, scriptSaveState, type BlockStatus } from '../scriptsLogic'

export interface RegisteredBlock {
  status: Ref<BlockStatus>
  flush: () => Promise<boolean>
}

export interface SaveRegistry {
  register: (id: string, block: RegisteredBlock) => void
  unregister: (id: string) => void
  state: ComputedRef<{ kind: 'saved' | 'saving' | 'stuck'; stuck: number }>
  unsaved: ComputedRef<boolean>
  /** The status of one registered block, or undefined when it is not registered (the section uses it to keep a deleted line's text on screen). */
  statusOf: (id: string) => BlockStatus | undefined
  /** Flushes every block; true when every one of them is saved afterwards. */
  flushAll: () => Promise<boolean>
}

const KEY: InjectionKey<SaveRegistry> = Symbol('script-save-registry')

export function provideSaveRegistry(): SaveRegistry {
  // Shallow: the map is reactive (blocks come and go), the blocks' own refs must stay refs.
  const blocks = shallowReactive(new Map<string, RegisteredBlock>())
  const statuses = computed<BlockStatus[]>(() => [...blocks.values()].map((b) => b.status.value))
  const registry: SaveRegistry = {
    register: (id, block) => void blocks.set(id, block),
    unregister: (id) => void blocks.delete(id),
    state: computed(() => scriptSaveState(statuses.value)),
    unsaved: computed(() => statuses.value.some(isUnsaved)),
    statusOf: (id) => blocks.get(id)?.status.value,
    flushAll: async () => {
      const results = await Promise.all([...blocks.values()].map((b) => b.flush()))
      return results.every(Boolean) && ![...blocks.values()].some((b) => isUnsaved(b.status.value))
    },
  }
  provide(KEY, registry)
  return registry
}

/** A block registers itself for as long as it lives. */
export function useRegisterBlock(id: string, block: RegisteredBlock): void {
  const registry = inject(KEY, null)
  if (registry === null) return
  registry.register(id, block)
  onBeforeUnmount(() => registry.unregister(id))
}

/** The open script's registry, or null outside an editor (a block read-only view needs no registry). */
export function useSaveRegistryRead(): SaveRegistry | null {
  return inject(KEY, null)
}
