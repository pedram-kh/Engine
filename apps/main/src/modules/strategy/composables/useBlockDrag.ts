/**
 * PL-3.2b — drag and drop of the blocks of one list (the hooks, the lines). The grip starts a drag; every block of the list is a
 * drop target; dropping puts the dragged block where the target is. Only a drag that STARTED on one of these grips counts, so a
 * file dragged in from the desktop is left to the image drop zones. The new order is decided by the list (it sends the whole list).
 */
import { ref, type Ref } from 'vue'

export function useBlockDrag(onDropOn: (id: string, targetId: string) => void): {
  dragging: Ref<string | null>
  over: Ref<string | null>
  start: (id: string, e: DragEvent) => void
  hover: (id: string, e: DragEvent) => void
  drop: (id: string, e: DragEvent) => void
  end: () => void
} {
  const dragging = ref<string | null>(null)
  const over = ref<string | null>(null)

  function end(): void {
    dragging.value = null
    over.value = null
  }
  return {
    dragging,
    over,
    start(id, e) {
      dragging.value = id
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move'
        e.dataTransfer.setData('text/plain', id)
        const card = (e.currentTarget as HTMLElement | null)?.closest('[data-block]')
        if (card instanceof HTMLElement) e.dataTransfer.setDragImage(card, 16, 16)
      }
    },
    hover(id, e) {
      if (dragging.value === null) return
      e.preventDefault()
      if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'
      over.value = id
    },
    drop(id, e) {
      const from = dragging.value
      if (from === null) return
      e.preventDefault()
      end()
      if (from !== id) onDropOn(from, id)
    },
    end,
  }
}
