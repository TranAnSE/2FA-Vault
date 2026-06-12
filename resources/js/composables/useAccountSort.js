import { useTwofaccounts } from '@/stores/twofaccounts'
import { useBusStore } from '@/stores/bus'
import { useSortable, moveArrayElement } from '@vueuse/integrations/useSortable'

/**
 * Composable for sortable drag-and-drop account reordering.
 */
export function useAccountSort() {
    const twofaccounts = useTwofaccounts()
    const bus = useBusStore()

    let stopSortable

    /**
     * Enables sortable behaviour on the twofaccounts list
     */
    function setSortable() {
        const { stop } = useSortable('#dv', twofaccounts.filtered, {
            animation: 200,
            handle: '.drag-handle',
            onUpdate: (e) => {
                const movedId = twofaccounts.filtered[e.oldIndex].id
                const inItemsIndex = twofaccounts.items.findIndex(item => item.id == movedId)
                moveArrayElement(twofaccounts.items, inItemsIndex, e.newIndex)
                nextTick(() => { twofaccounts.saveOrder('free') })
            }
        })
        stopSortable = stop
    }

    return { setSortable }
}
