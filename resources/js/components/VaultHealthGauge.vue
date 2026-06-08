<script setup>
    const props = defineProps({
        score: { type: Number, default: 0 },
        size: { type: Number, default: 120 },
    })

    const radius = computed(() => props.size / 2 - 10)
    const circumference = computed(() => 2 * Math.PI * radius.value)
    const dashOffset = computed(() => circumference.value * (1 - props.score / 100))

    const color = computed(() => {
        if (props.score >= 76) return '#48c78e'  // green
        if (props.score >= 51) return '#ffe08a'  // yellow
        if (props.score >= 26) return '#ff9900'  // orange
        return '#f14668'                          // red
    })

    const label = computed(() => {
        if (props.score >= 76) return 'Good'
        if (props.score >= 51) return 'Fair'
        if (props.score >= 26) return 'Warning'
        return 'Critical'
    })
</script>

<template>
    <div class="vault-health-gauge" :style="{ width: size + 'px', height: size + 'px' }">
        <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`">
            <circle
                :cx="size / 2"
                :cy="size / 2"
                :r="radius"
                fill="none"
                stroke="#ededed"
                stroke-width="10"
            />
            <circle
                :cx="size / 2"
                :cy="size / 2"
                :r="radius"
                fill="none"
                :stroke="color"
                stroke-width="10"
                stroke-linecap="round"
                :stroke-dasharray="circumference"
                :stroke-dashoffset="dashOffset"
                transform="rotate(-90)"
                :transform-origin="`${size / 2} ${size / 2}`"
                style="transition: stroke-dashoffset 0.6s ease"
            />
        </svg>
        <div class="gauge-label">
            <span class="score" :style="{ color }">{{ score }}</span>
            <span class="status is-size-7">{{ label }}</span>
        </div>
    </div>
</template>

<style scoped>
.vault-health-gauge {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.gauge-label {
    position: absolute;
    display: flex;
    flex-direction: column;
    align-items: center;
    line-height: 1;
}
.score {
    font-size: 1.75rem;
    font-weight: bold;
}
</style>
