/**
 * PL-2.3 (A1) — the one question asked before a change that sends an Approved concept back to Awaiting client. The page
 * provides it (one dialog for the whole step); a card, the references and the edit dialog ask it before their first such
 * change. Without a provider (a component on its own in a test) it agrees at once.
 *
 * It is asked only for a concept that is Approved: Changes requested stays red and Awaiting has nothing to lose.
 */
import type { WaveConceptResource } from '@catalyst/api-client'
import { inject, provide, type InjectionKey } from 'vue'

export type ConfirmReset = (concept: WaveConceptResource) => Promise<boolean>

const KEY: InjectionKey<ConfirmReset> = Symbol('strategy.confirmReset')

export function provideConfirmReset(fn: ConfirmReset): void {
  provide(KEY, fn)
}

export function useConfirmReset(): ConfirmReset {
  return inject(KEY, () => Promise.resolve(true))
}
