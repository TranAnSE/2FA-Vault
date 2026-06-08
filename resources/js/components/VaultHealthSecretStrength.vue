<script setup>
    const props = defineProps({
        weakSecrets: { type: Array, default: () => [] },
        totalAccounts: { type: Number, default: 0 },
    })

    const expanded = ref(false)

    function ratingColor(rating) {
        if (rating >= 70) return 'is-success'
        if (rating >= 40) return 'is-warning'
        return 'is-danger'
    }
</script>

<template>
    <div class="vault-health-section">
        <div class="is-flex is-justify-content-space-between is-align-items-center mb-2">
            <h5 class="title is-5 mb-0">
                {{ $t('title.weak_secrets') }}
                <span class="tag ml-2" :class="weakSecrets.length ? 'is-danger' : 'is-success'">
                    {{ weakSecrets.length }}
                </span>
            </h5>
            <button v-if="weakSecrets.length" class="button is-small is-ghost" @click="expanded = !expanded">
                {{ expanded ? $t('label.collapse') : $t('label.expand') }}
            </button>
        </div>

        <p class="is-size-7 has-text-grey mb-3">
            {{ $t('message.vault_health_weak_secrets_legend') }}
        </p>

        <template v-if="expanded">
            <div v-for="item in weakSecrets" :key="item.account.id" class="mb-3">
                <div class="is-flex is-align-items-center is-justify-content-space-between mb-1">
                    <span class="is-size-6">{{ item.account.service || $t('label.no_service') }} — {{ item.account.account }}</span>
                    <span class="tag is-size-7" :class="ratingColor(item.rating)">{{ item.rating }}/100</span>
                </div>
                <progress class="progress is-small" :class="ratingColor(item.rating)" :value="item.rating" max="100"></progress>
                <p class="is-size-7 has-text-grey">
                    {{ (item.account.algorithm || 'sha1').toUpperCase() }} · {{ item.account.digits ?? 6 }} {{ $t('label.digits') }} · {{ item.entropy }} {{ $t('label.bits_entropy') }}
                </p>
            </div>
        </template>

        <p v-else-if="!weakSecrets.length" class="is-size-7 has-text-success">
            {{ $t('message.vault_health_all_secrets_strong') }}
        </p>
    </div>
</template>
