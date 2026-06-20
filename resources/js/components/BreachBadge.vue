<script setup>
/**
 * Warning badge shown when an account's service or email appears in a breach.
 * Pure presentational component fed by breachService results.
 */
import { computed } from 'vue'

const props = defineProps({
    breached: { type: Boolean, default: false },
    count: { type: Number, default: 0 },
    source: { type: String, default: 'hibp' }, // 'hibp' | 'unknown'
})

const label = computed(() => {
    if (props.source === 'unknown') return '?'
    return props.breached ? '!' : '✓'
})

const colorClass = computed(() => {
    if (props.source === 'unknown') return 'is-light'
    return props.breached ? 'is-danger' : 'is-success'
})

const title = computed(() => {
    if (props.source === 'unknown') return 'Breach status unknown (service unavailable)'
    return props.breached
        ? `Service appears in ${props.count} known breach(es)`
        : 'No known breaches for this service'
})
</script>

<template>
    <span class="tag breach-badge" :class="colorClass" :title="title">
        {{ label }}
    </span>
</template>

<style scoped>
.breach-badge {
    font-weight: 700;
    min-width: 1.6em;
}
</style>
