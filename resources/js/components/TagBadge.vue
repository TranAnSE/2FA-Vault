<script setup>
    defineProps({
        tag:       { type: Object,  required: true },
        removable: { type: Boolean, default: false },
    })
    defineEmits(['remove'])
</script>

<template>
    <span
        class="tag-badge"
        :style="{
            '--tag-color': tag.color ?? '#3273dc',
            background: (tag.color ?? '#3273dc') + '22',
            border: `1px solid ${tag.color ?? '#3273dc'}55`,
            color: tag.color ?? '#3273dc',
        }"
    >
        {{ tag.name }}
        <button v-if="removable" class="tag-badge__remove" type="button" @click.stop="$emit('remove')">×</button>
    </span>
</template>

<style scoped>
.tag-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.72rem;
    font-weight: 500;
    line-height: 1.5;
    white-space: nowrap;
}
.tag-badge__remove {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 0.9rem;
    line-height: 1;
    padding: 0 0 0 2px;
    color: inherit;
    opacity: 0.7;
}
.tag-badge__remove:hover { opacity: 1; }
</style>
