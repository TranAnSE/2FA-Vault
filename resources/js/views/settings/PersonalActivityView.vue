<script setup>
    import tabs from './tabs'
    import personalActivityService from '@/services/personalActivityService'
    import { useNotify, TabBar } from '@2fauth/ui'

    const { t } = useI18n()
    const notify = useNotify()
    const router = useRouter()

    const entries = ref([])
    const meta = ref({ current_page: 1, last_page: 1, total: 0 })
    const page = ref(1)
    const isLoading = ref(false)

    onMounted(() => load(1))

    async function load(p = 1) {
        isLoading.value = true
        try {
            const { data } = await personalActivityService.list(p)
            const payload = data.data ?? data
            entries.value = Array.isArray(payload) ? payload : []
            if (data.meta) meta.value = data.meta
            page.value = data.meta?.current_page ?? p
        } catch {
            entries.value = []
        } finally {
            isLoading.value = false
        }
    }

    function badgeClass(action) {
        const a = String(action || '').toLowerCase()
        if (a.includes('create') || a.includes('store')) return 'is-success is-light'
        if (a.includes('delete') || a.includes('destroy')) return 'is-danger is-light'
        if (a.includes('auth') || a.includes('login') || a.includes('logout')) return 'is-info is-light'
        if (a.includes('update') || a.includes('patch')) return 'is-warning is-light'
        return 'is-light'
    }

    async function clearAll() {
        if (!confirm(t('confirmation.clear_activity'))) return
        try {
            await personalActivityService.clearAll()
            notify.success({ text: t('notification.activity_cleared') })
            await load(1)
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }
</script>

<template>
    <div>
        <TabBar :tabs="tabs" :active-tab="'settings.activity'" @tab-selected="(to) => router.push({ name: to })" />
    <div class="container py-5">
        <div class="level mb-4">
            <div class="level-left">
                <h2 class="title is-3 mb-0">{{ $t('title.personal_activity') }}</h2>
            </div>
            <div class="level-right">
                <button class="button is-danger is-light" :disabled="!entries.length" @click="clearAll">{{ $t('label.clear_all') }}</button>
            </div>
        </div>

        <div v-if="isLoading" class="has-text-grey">{{ $t('label.loading') }}</div>

        <div v-else-if="!entries.length" class="notification is-light has-text-grey">
            {{ $t('message.no_activity_yet') }}
        </div>

        <div v-else class="box">
            <table class="table is-fullwidth is-narrow">
                <thead>
                    <tr>
                        <th>{{ $t('label.action') }}</th>
                        <th>{{ $t('label.ip_address') }}</th>
                        <th>{{ $t('label.user_agent') }}</th>
                        <th>{{ $t('label.date') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in entries" :key="e.id">
                        <td><span class="tag is-size-7" :class="badgeClass(e.action)">{{ e.action }}</span></td>
                        <td>{{ e.ip_address || '—' }}</td>
                        <td class="has-text-grey is-size-7" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ e.user_agent || '—' }}</td>
                        <td>{{ e.created_at ? new Date(e.created_at).toLocaleString() : '—' }}</td>
                    </tr>
                </tbody>
            </table>

            <div v-if="meta.last_page > 1" class="is-flex is-justify-content-center mt-4">
                <nav class="pagination is-small" role="navigation">
                    <button class="button pagination-previous" :disabled="page <= 1" @click="load(page - 1)">{{ $t('pagination.previous') }}</button>
                    <button class="button pagination-next" :disabled="page >= meta.last_page" @click="load(page + 1)">{{ $t('pagination.next') }}</button>
                </nav>
            </div>
        </div>
    </div>
    </div>
</template>
