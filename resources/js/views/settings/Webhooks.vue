<script setup>
    import webhookService from '@/services/webhookService'
    import { useNotify } from '@2fauth/ui'

    const { t } = useI18n()
    const notify = useNotify()

    const webhooks     = ref([])
    const events       = ref([])
    const isLoading    = ref(false)
    const showCreate   = ref(false)
    const selectedWebhook = ref(null)
    const deliveries      = ref([])

    const form = reactive({ name: '', url: '', secret: '', events: [] })

    onMounted(async () => {
        await Promise.all([loadWebhooks(), loadEvents()])
    })

    async function loadWebhooks() {
        isLoading.value = true
        const { data } = await webhookService.getAll().catch(() => ({ data: [] }))
        webhooks.value = data
        isLoading.value = false
    }

    async function loadEvents() {
        const { data } = await webhookService.getEvents().catch(() => ({ data: [] }))
        events.value = data
    }

    async function createWebhook() {
        if (!form.name || !form.url || !form.events.length) return
        try {
            const { data } = await webhookService.create({ ...form })
            webhooks.value.push(data)
            Object.assign(form, { name: '', url: '', secret: '', events: [] })
            showCreate.value = false
            notify.success({ text: t('notification.webhook_created') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }

    async function deleteWebhook(w) {
        if (!confirm(t('confirmation.delete_webhook', { name: w.name }))) return
        await webhookService.delete(w.id)
        webhooks.value = webhooks.value.filter(x => x.id !== w.id)
        notify.success({ text: t('notification.webhook_deleted') })
    }

    async function toggleActive(w) {
        const { data } = await webhookService.update(w.id, { is_active: !w.is_active })
        const idx = webhooks.value.findIndex(x => x.id === data.id)
        if (idx !== -1) webhooks.value[idx] = data
    }

    async function testWebhook(w) {
        await webhookService.test(w.id)
        notify.success({ text: t('notification.webhook_test_sent') })
    }

    async function showDeliveries(w) {
        selectedWebhook.value = w
        const { data } = await webhookService.getDeliveries(w.id)
        deliveries.value = data
    }

    function toggleEvent(eventValue) {
        const idx = form.events.indexOf(eventValue)
        if (idx === -1) form.events.push(eventValue)
        else form.events.splice(idx, 1)
    }
</script>

<template>
    <div class="container py-5">
        <div class="level mb-4">
            <div class="level-left">
                <h2 class="title is-3 mb-0">{{ $t('title.webhooks') }}</h2>
            </div>
            <div class="level-right">
                <VueButton class="button is-primary" @click="showCreate = !showCreate">{{ $t('label.create_webhook') }}</VueButton>
            </div>
        </div>

        <!-- Create form -->
        <div v-if="showCreate" class="box mb-4">
            <h4 class="title is-5">{{ $t('label.create_webhook') }}</h4>
            <div class="field">
                <label class="label is-size-7">{{ $t('field.webhook_name') }}</label>
                <input class="input" type="text" v-model="form.name" :placeholder="$t('field.webhook_name')" />
            </div>
            <div class="field">
                <label class="label is-size-7">{{ $t('field.webhook_url') }}</label>
                <input class="input" type="url" v-model="form.url" placeholder="https://your-endpoint.example.com/hook" />
            </div>
            <div class="field">
                <label class="label is-size-7">{{ $t('field.webhook_secret') }}</label>
                <input class="input" type="text" v-model="form.secret" :placeholder="$t('field.webhook_secret_help')" />
            </div>
            <div class="field">
                <label class="label is-size-7">{{ $t('label.events') }}</label>
                <div style="display:flex;flex-wrap:wrap;gap:4px">
                    <label v-for="ev in events" :key="ev.value" class="tag is-clickable" :class="form.events.includes(ev.value) ? 'is-info' : 'is-light'" @click="toggleEvent(ev.value)">
                        {{ ev.value }}
                    </label>
                </div>
            </div>
            <div class="buttons mt-3">
                <VueButton class="button is-primary" @click="createWebhook">{{ $t('label.save') }}</VueButton>
                <VueButton class="button" @click="showCreate = false">{{ $t('label.cancel') }}</VueButton>
            </div>
        </div>

        <!-- Webhook list -->
        <div class="box">
            <p v-if="!webhooks.length" class="has-text-grey">{{ $t('message.no_webhooks_yet') }}</p>
            <div v-for="w in webhooks" :key="w.id" class="mb-3 p-3" style="border:1px solid #ededed;border-radius:6px">
                <div class="is-flex is-align-items-center is-justify-content-space-between">
                    <div>
                        <span class="has-text-weight-semibold">{{ w.name }}</span>
                        <span class="is-size-7 has-text-grey ml-2">{{ w.url }}</span>
                        <span class="tag ml-2 is-size-7" :class="w.is_active ? 'is-success is-light' : 'is-light'">
                            {{ w.is_active ? $t('label.active') : $t('label.inactive') }}
                        </span>
                    </div>
                    <div class="buttons are-small mb-0">
                        <VueButton class="button is-light" @click="toggleActive(w)">{{ w.is_active ? $t('label.disable') : $t('label.enable') }}</VueButton>
                        <VueButton class="button is-info is-light" @click="testWebhook(w)">{{ $t('label.test') }}</VueButton>
                        <VueButton class="button is-light" @click="showDeliveries(w)">{{ $t('label.history') }}</VueButton>
                        <VueButton class="button is-danger is-light" @click="deleteWebhook(w)">{{ $t('label.delete') }}</VueButton>
                    </div>
                </div>
                <div class="mt-1" style="display:flex;flex-wrap:wrap;gap:3px">
                    <span v-for="ev in w.events" :key="ev" class="tag is-info is-light is-size-7">{{ ev }}</span>
                </div>
            </div>
        </div>

        <!-- Delivery history modal -->
        <div v-if="selectedWebhook" class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="webhook-delivery-title" @keydown.escape="selectedWebhook = null">
            <div class="modal-background" @click="selectedWebhook = null" aria-hidden="true"></div>
            <div class="modal-card">
                <header class="modal-card-head">
                    <p class="modal-card-title" id="webhook-delivery-title">{{ $t('label.delivery_history') }}: {{ selectedWebhook.name }}</p>
                    <button class="delete" :aria-label="$t('label.close')" @click="selectedWebhook = null"></button>
                </header>
                <section class="modal-card-body">
                    <p v-if="!deliveries.length" class="has-text-grey">{{ $t('message.no_deliveries_yet') }}</p>
                    <table v-else class="table is-fullwidth is-narrow is-size-7">
                        <thead>
                            <tr><th>{{ $t('label.event') }}</th><th>{{ $t('label.status') }}</th><th>{{ $t('label.date') }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in deliveries" :key="d.id">
                                <td>{{ d.event }}</td>
                                <td><span class="tag is-size-7" :class="d.success ? 'is-success' : 'is-danger'">{{ d.status_code ?? '—' }}</span></td>
                                <td>{{ new Date(d.created_at).toLocaleString() }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </div>
</template>
