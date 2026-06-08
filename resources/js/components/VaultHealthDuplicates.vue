<script setup>
    const props = defineProps({
        duplicateGroups: { type: Array, default: () => [] },
    })

    const expanded = ref(false)
</script>

<template>
    <div class="vault-health-section">
        <div class="is-flex is-justify-content-space-between is-align-items-center mb-2">
            <h5 class="title is-5 mb-0">
                {{ $t('title.duplicate_accounts') }}
                <span class="tag ml-2" :class="duplicateGroups.length ? 'is-warning' : 'is-success'">
                    {{ duplicateGroups.length }}
                </span>
            </h5>
            <button v-if="duplicateGroups.length" class="button is-small is-ghost" @click="expanded = !expanded">
                {{ expanded ? $t('label.collapse') : $t('label.expand') }}
            </button>
        </div>

        <p class="is-size-7 has-text-grey mb-3">
            {{ $t('message.vault_health_duplicates_legend') }}
        </p>

        <template v-if="expanded">
            <div v-for="(group, i) in duplicateGroups" :key="i" class="box mb-3 p-3">
                <p class="is-size-7 has-text-grey mb-2">{{ $t('message.vault_health_duplicate_group', { n: i + 1 }) }}</p>
                <div v-for="account in group" :key="account.id" class="is-flex is-align-items-center mb-1">
                    <span class="icon is-small has-text-warning mr-2"><i class="mdi mdi-alert-circle"></i></span>
                    <span class="is-size-6">{{ account.service || $t('label.no_service') }} — {{ account.account }}</span>
                </div>
            </div>
        </template>

        <p v-else-if="!duplicateGroups.length" class="is-size-7 has-text-success">
            {{ $t('message.vault_health_no_duplicates') }}
        </p>
    </div>
</template>
