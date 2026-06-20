<script setup>
/**
 * Letter-grade badge (A–F) for an account's security health.
 * Color-coded; shows an indicator distinguishing a combined (unlocked vault)
 * score from a server-only (locked vault) score.
 */
import { computed } from 'vue'

const props = defineProps({
    grade: { type: String, default: null },
    total: { type: Number, default: null },
    mode: { type: String, default: 'server-only' }, // 'combined' | 'server-only'
})

const colorClass = computed(() => {
    switch (props.grade) {
        case 'A': return 'is-success'
        case 'B': return 'is-info'
        case 'C': return 'is-warning'
        case 'D': return 'is-danger'
        case 'F': return 'is-danger is-dark'
        default: return 'is-light'
    }
})

const title = computed(() => {
    if (!props.grade) return ''
    const score = props.total != null ? ` (${props.total})` : ''
    const basis = props.mode === 'combined' ? 'combined' : 'server-only'
    return `Health grade ${props.grade}${score} · ${basis}`
})
</script>

<template>
    <span v-if="grade" class="tag health-badge" :class="colorClass" :title="title">
        {{ grade }}
        <span v-if="mode === 'server-only'" class="health-badge__lock" aria-hidden="true">🔒</span>
    </span>
</template>

<style scoped>
.health-badge {
    font-weight: 700;
    min-width: 1.8em;
}
.health-badge__lock {
    margin-left: 0.25em;
    font-size: 0.75em;
    opacity: 0.85;
}
</style>
