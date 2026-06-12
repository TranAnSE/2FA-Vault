<template>
    <div class="modal" :class="{ 'is-active': show }" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title" @keydown.escape="$emit('close')">
        <div class="modal-background" @click="$emit('close')" aria-hidden="true"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title" id="edit-modal-title">{{ $t('teams.edit_team') }}</p>
                <button class="delete" :aria-label="$t('label.close')" @click="$emit('close')"></button>
            </header>
            <section class="modal-card-body">
                <div class="field">
                    <label class="label">{{ $t('teams.team_name') }}</label>
                    <div class="control">
                        <input class="input" type="text" v-model="teamName" />
                    </div>
                </div>
            </section>
            <footer class="modal-card-foot">
                <button @click="$emit('save', teamName)" class="button is-success">{{ $t('teams.save') }}</button>
                <button @click="$emit('close')" class="button">{{ $t('teams.cancel') }}</button>
            </footer>
        </div>
    </div>
</template>

<script setup>
    const props = defineProps({
        show: { type: Boolean, required: true },
        name: { type: String, default: '' },
    })

    defineEmits(['close', 'save'])

    const teamName = ref(props.name)

    watch(() => props.name, (val) => { teamName.value = val })
</script>
