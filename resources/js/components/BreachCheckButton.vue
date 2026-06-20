<script setup>
/**
 * "Check for breaches" control for a single account service.
 *
 * Calls the public breach service check (no opt-in needed — only a public
 * service/domain name is sent to HIBP). Shows a BreachBadge with the result and
 * a short status message. Emits nothing; purely presentational + side-effecting
 * on click.
 */
import { ref } from 'vue'
import { useNotify } from '@2fauth/ui'
import { useI18n } from 'vue-i18n'
import breachService from '@/services/breachService'
import BreachBadge from '@/components/BreachBadge.vue'

const props = defineProps({
    service: { type: String, default: '' },
})

const notify = useNotify()
const { t } = useI18n()

const loading = ref(false)
const result = ref(null) // { breached, count, source }

async function check() {
    const service = (props.service || '').trim()
    if (!service) {
        notify.alert({ text: t('breach.status_unknown') })
        return
    }
    loading.value = true
    try {
        const { data } = await breachService.checkService(service)
        result.value = data
        if (data.source === 'unknown') {
            notify.warn({ text: t('breach.status_unknown') })
        } else if (data.breached) {
            notify.warn({ text: t('breach.service_breached', { service, count: data.count }) })
        } else {
            notify.success({ text: t('breach.service_clean', { service }) })
        }
    } catch (error) {
        notify.alert({ text: error.response?.data?.message || t('breach.status_unknown') })
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <div class="breach-check field">
        <button type="button" class="button is-small is-rounded"
            :class="{ 'is-loading': loading }"
            :disabled="loading || !service"
            @click.prevent="check">
            {{ $t('label.check_for_breaches') }}
        </button>
        <BreachBadge v-if="result"
            :breached="result.breached"
            :count="result.count"
            :source="result.source" />
    </div>
</template>

<style scoped>
.breach-check {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
</style>
