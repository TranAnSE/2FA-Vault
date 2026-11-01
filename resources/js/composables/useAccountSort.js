import { useTwofaccounts } from '@/stores/twofaccounts'
import { useSortable, moveArrayElement } from '@vueuse/integrations/useSortable'

/**
 * Composable for sortable drag-and-drop account reordering.
 *
 * A single Sortable instance is created for the #dv container (span in
 * grid/list modes, tbody in desktop-table mode) and started/stopped by the
 * consumer through a watcher — rebinding a fresh instance on every mode or
 * list change left stale spans bound over a swapped element type.
 */
export function useAccountSort() {
    const twofaccounts = useTwofaccounts()

    // The onUpdate mapping translates the position in the FILTERED list —
    // what the user actually dragged — into the position in the full items
    // list, so a drag while a filter (favorites-only, group, search) is
    // active reorders the right accounts.
    const { start, stop } = useSortable('#dv', twofaccounts.filtered, {
        animation: 200,
        handle: '.drag-handle',
        onUpdate: (e) => {
            const movedId = twofaccounts.filtered[e.oldIndex].id
            const inItemsIndex = twofaccounts.items.findIndex(item => item.id == movedId)
            moveArrayElement(twofaccounts.items, inItemsIndex, e.newIndex)
            nextTick(() => { twofaccounts.saveOrder('free') })
        }
    })

    return { startSortable: start, stopSortable: stop }
}
