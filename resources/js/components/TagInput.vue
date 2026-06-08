<script setup>
    import TagBadge from './TagBadge.vue'

    const props = defineProps({
        modelValue: { type: Array, default: () => [] }, // array of tag objects
        allTags:    { type: Array, default: () => [] },
    })
    const emit = defineEmits(['update:modelValue'])

    const search    = ref('')
    const showList  = ref(false)

    const suggestions = computed(() => {
        if (!search.value.trim()) return []
        const lower = search.value.toLowerCase()
        const selectedIds = new Set(props.modelValue.map(t => t.id))
        return props.allTags.filter(t => t.name.toLowerCase().includes(lower) && !selectedIds.has(t.id))
    })

    function addTag(tag) {
        if (!props.modelValue.find(t => t.id === tag.id)) {
            emit('update:modelValue', [...props.modelValue, tag])
        }
        search.value = ''
        showList.value = false
    }

    function removeTag(tag) {
        emit('update:modelValue', props.modelValue.filter(t => t.id !== tag.id))
    }

    function onInput() {
        showList.value = search.value.trim().length > 0
    }

    function onBlur() {
        setTimeout(() => { showList.value = false }, 150)
    }
</script>

<template>
    <div class="tag-input-wrapper">
        <div class="tag-input-field">
            <TagBadge
                v-for="tag in modelValue"
                :key="tag.id"
                :tag="tag"
                removable
                @remove="removeTag(tag)"
            />
            <input
                v-model="search"
                class="tag-input-text"
                type="text"
                :placeholder="modelValue.length === 0 ? $t('label.add_tags') : ''"
                @input="onInput"
                @blur="onBlur"
                @keydown.backspace="search === '' && modelValue.length > 0 && removeTag(modelValue[modelValue.length - 1])"
            />
        </div>
        <ul v-if="showList && suggestions.length" class="tag-suggestions">
            <li v-for="tag in suggestions" :key="tag.id" class="tag-suggestion-item" @mousedown="addTag(tag)">
                <span class="color-dot" :style="{ background: tag.color }"></span>
                {{ tag.name }}
            </li>
        </ul>
    </div>
</template>

<style scoped>
.tag-input-wrapper { position: relative; }
.tag-input-field {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    align-items: center;
    min-height: 2.25rem;
    padding: 4px 8px;
    border: 1px solid #dbdbdb;
    border-radius: 4px;
    background: #fff;
    cursor: text;
}
.tag-input-text {
    border: none;
    outline: none;
    flex: 1;
    min-width: 80px;
    font-size: 0.875rem;
    background: transparent;
}
.tag-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: #fff;
    border: 1px solid #dbdbdb;
    border-radius: 4px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    list-style: none;
    margin: 2px 0 0;
    padding: 4px 0;
    z-index: 100;
    max-height: 180px;
    overflow-y: auto;
}
.tag-suggestion-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    cursor: pointer;
    font-size: 0.875rem;
}
.tag-suggestion-item:hover { background: #f5f5f5; }
.color-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
</style>
