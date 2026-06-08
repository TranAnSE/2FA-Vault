<script setup>
    const props = defineProps({
        unused: { type: Array, default: () => [] },
    })

    const expanded = ref(false)
</script>

<template>
    <div class="vault-health-section">
        <div class="is-flex is-justify-content-space-between is-align-items-center mb-2">
            <h5 class="title is-5 mb-0">
                {{ $t('title.unused_accounts') }}
                <span class="tag ml-2" :class="unused.length ? 'is-warning' : 'is-success'">
                    {{ unused.length }}
                </span>
            </h5>
            <button v-if="unused.length" class="button is-small is-ghost" @click="expanded = !expanded">
                {{ expanded ? $t('label.collapse') : $t('label.expand') }}
            </button>
        </div>

        <p class="is-size-7 has-text-grey mb-3">
            {{ $t('message.vault_health_unused_legend') }}
        </p>

        <template v-if="expanded">
            <div v-for="account in unused" :key="account.id" class="is-flex is-align-items-center mb-2">
                <span class="icon is-small has-text-warning mr-2"><i class="mdi mdi-clock-outline"></i></span>
                <span class="is-size-6">
                    {{ account.service || $t('label.no_service') }} — {{ account.account }}
                    <span v-if="account.last_used_at" class="is-size-7 has-text-grey ml-2">
                        ({{ $t('message.last_used', { date: new Date(account.last_used_at).toLocaleDateString() }) }})
                    </span>
                    <span v-else class="is-size-7 has-text-grey ml-2">({{ $t('label.never_used') }})</span>
                </span>
            </div>
        </template>

        <p v-else-if="!unused.length" class="is-size-7 has-text-success">
            {{ $t('message.vault_health_no_unused') }}
        </p>
    </div>
</template>
