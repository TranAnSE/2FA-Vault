<script setup>
    import TagBadge from '@/components/TagBadge.vue'
    import { saveFilterPreset, loadFilterPresets, deleteFilterPreset } from '@/services/searchService'

    const props = defineProps({
        filters: { type: Object, default: () => ({}) },
        groups:  { type: Array,  default: () => [] },
        tags:    { type: Array,  default: () => [] },
    })
    const emit = defineEmits(['update', 'close'])

    const local = reactive({
        types:          [...(props.filters.types ?? [])],
        algorithms:     [...(props.filters.algorithms ?? [])],
        digits:         [...(props.filters.digits ?? [])],
        group_id:       props.filters.group_id ?? null,
        tag_ids:        [...(props.filters.tag_ids ?? [])],
        tag_mode:       props.filters.tag_mode ?? 'or',
        encrypted:      props.filters.encrypted ?? null,
        last_used_from: props.filters.last_used_from ?? '',
        last_used_to:   props.filters.last_used_to ?? '',
    })

    const presetName  = ref('')
    const presets     = ref(loadFilterPresets())

    function toggle(arr, val) {
        const idx = arr.indexOf(val)
        if (idx === -1) arr.push(val)
        else arr.splice(idx, 1)
    }

    function apply() { emit('update', { ...local }) }
    function reset()  { Object.assign(local, { types: [], algorithms: [], digits: [], group_id: null, tag_ids: [], tag_mode: 'or', encrypted: null, last_used_from: '', last_used_to: '' }); apply() }

    function savePreset() {
        if (!presetName.value.trim()) return
        saveFilterPreset(presetName.value.trim(), { ...local })
        presets.value = loadFilterPresets()
        presetName.value = ''
    }

    function loadPreset(name) {
        const p = presets.value[name]
        if (p) Object.assign(local, p)
        apply()
    }

    function removePreset(name) {
        deleteFilterPreset(name)
        presets.value = loadFilterPresets()
    }

    watch(local, apply, { deep: true })
</script>

<template>
    <div class="filter-panel box mt-2 p-4">
        <div class="is-flex is-justify-content-space-between is-align-items-center mb-3">
            <p class="has-text-weight-semibold">{{ $t('label.filters') }}</p>
            <div class="buttons are-small">
                <VueButton class="button is-light" @click="reset">{{ $t('label.reset') }}</VueButton>
                <VueButton class="button is-ghost" @click="$emit('close')">×</VueButton>
            </div>
        </div>

        <!-- OTP Type -->
        <div class="field mb-3">
            <label class="label is-size-7">{{ $t('label.otp_type') }}</label>
            <div class="buttons are-small">
                <button v-for="type in ['totp','hotp','steamtotp']" :key="type"
                    class="button" :class="local.types.includes(type) ? 'is-info' : 'is-light'"
                    @click="toggle(local.types, type)">
                    {{ type.toUpperCase() }}
                </button>
            </div>
        </div>

        <!-- Algorithm -->
        <div class="field mb-3">
            <label class="label is-size-7">{{ $t('label.algorithm') }}</label>
            <div class="buttons are-small">
                <button v-for="alg in ['sha1','sha256','sha512']" :key="alg"
                    class="button" :class="local.algorithms.includes(alg) ? 'is-info' : 'is-light'"
                    @click="toggle(local.algorithms, alg)">
                    {{ alg.toUpperCase() }}
                </button>
            </div>
        </div>

        <!-- Digits -->
        <div class="field mb-3">
            <label class="label is-size-7">{{ $t('label.digits') }}</label>
            <div class="buttons are-small">
                <button v-for="d in [6, 7, 8]" :key="d"
                    class="button" :class="local.digits.includes(d) ? 'is-info' : 'is-light'"
                    @click="toggle(local.digits, d)">
                    {{ d }}
                </button>
            </div>
        </div>

        <!-- Group -->
        <div class="field mb-3">
            <label class="label is-size-7">{{ $t('label.group') }}</label>
            <div class="select is-small">
                <select v-model="local.group_id">
                    <option :value="null">{{ $t('label.all_groups') }}</option>
                    <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
                </select>
            </div>
        </div>

        <!-- Tags -->
        <div v-if="tags.length" class="field mb-3">
            <label class="label is-size-7">{{ $t('label.tags') }}</label>
            <div class="mb-1" style="display:flex;flex-wrap:wrap;gap:4px">
                <span v-for="tag in tags" :key="tag.id"
                    style="cursor:pointer"
                    @click="toggle(local.tag_ids, tag.id)">
                    <TagBadge :tag="tag"
                        :style="{ opacity: local.tag_ids.includes(tag.id) ? 1 : 0.4 }" />
                </span>
            </div>
            <div class="buttons are-small mt-1">
                <button class="button is-small" :class="local.tag_mode === 'or' ? 'is-info' : 'is-light'" @click="local.tag_mode = 'or'">OR</button>
                <button class="button is-small" :class="local.tag_mode === 'and' ? 'is-info' : 'is-light'" @click="local.tag_mode = 'and'">AND</button>
            </div>
        </div>

        <!-- Encryption status -->
        <div class="field mb-3">
            <label class="label is-size-7">{{ $t('label.encryption_status') }}</label>
            <div class="buttons are-small">
                <button class="button" :class="local.encrypted === null ? 'is-info' : 'is-light'" @click="local.encrypted = null">{{ $t('label.all') }}</button>
                <button class="button" :class="local.encrypted === true ? 'is-info' : 'is-light'" @click="local.encrypted = true">{{ $t('label.encrypted') }}</button>
                <button class="button" :class="local.encrypted === false ? 'is-info' : 'is-light'" @click="local.encrypted = false">{{ $t('label.not_encrypted') }}</button>
            </div>
        </div>

        <!-- Presets -->
        <div class="field mt-3">
            <label class="label is-size-7">{{ $t('label.filter_presets') }}</label>
            <div class="field has-addons mb-2">
                <div class="control is-expanded">
                    <input class="input is-small" type="text" v-model="presetName" :placeholder="$t('field.preset_name')" @keydown.enter="savePreset" />
                </div>
                <div class="control">
                    <button class="button is-small is-info" @click="savePreset">{{ $t('label.save') }}</button>
                </div>
            </div>
            <div v-if="Object.keys(presets).length" style="display:flex;flex-wrap:wrap;gap:4px">
                <span v-for="(_, name) in presets" :key="name" class="tag is-info is-light" style="cursor:pointer" @click="loadPreset(name)">
                    {{ name }}
                    <button class="delete is-small" @click.stop="removePreset(name)"></button>
                </span>
            </div>
        </div>
    </div>
</template>
