<script setup>
    import tabs from './tabs'
    import userSessionService from '@/services/userSessionService'
    import { useUserStore } from '@/stores/user'
    import { useNotify, TabBar } from '@2fauth/ui'
    import { LucideMonitor, LucideSmartphone, LucideTrash2 } from 'lucide-vue-next'

    const router = useRouter()

    const { t } = useI18n()
    const notify = useNotify()
    const user = useUserStore()

    const sessions = ref([])
    const currentTokenId = computed(() => user.preferences?.currentTokenId ?? null)
    const isLoading = ref(false)
    const pendingRevoke = ref(null)

    onMounted(load)

    async function load() {
        isLoading.value = true
        try {
            const { data } = await userSessionService.list()
            sessions.value = data.data ?? data ?? []
        } catch {
            sessions.value = []
        } finally {
            isLoading.value = false
        }
    }

    function deviceIcon(ua) {
        const s = String(ua || '').toLowerCase()
        if (/mobile|android|iphone|ipod/.test(s)) return LucideSmartphone
        return LucideMonitor
    }

    function masked(token) {
        const v = String(token || '')
        if (v.length <= 8) return v
        return v.slice(0, 4) + '…' + v.slice(-4)
    }

    function isCurrent(s) {
        if (!s) return false
        return s.is_current === true || (currentTokenId.value && s.id == currentTokenId.value)
    }

    async function confirmRevoke() {
        if (!pendingRevoke.value) return
        const id = pendingRevoke.value.id
        pendingRevoke.value = null
        try {
            await userSessionService.revoke(id)
            sessions.value = sessions.value.filter(s => s.id !== id)
            notify.success({ text: t('notification.session_revoked') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }

    async function revokeAllOthers() {
        if (!confirm(t('confirmation.revoke_all_sessions'))) return
        try {
            await userSessionService.revokeAll()
            notify.success({ text: t('notification.sessions_revoked') })
            await load()
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }
</script>

<template>
    <div>
        <TabBar :tabs="tabs" :active-tab="'settings.security.sessions'" @tab-selected="(to) => router.push({ name: to })" />
    <div class="container py-5">
        <div class="level mb-4">
            <div class="level-left">
                <h2 class="title is-3 mb-0">{{ $t('title.sessions') }}</h2>
            </div>
            <div class="level-right">
                <button class="button is-danger is-light" :disabled="sessions.length <= 1" @click="revokeAllOthers">{{ $t('label.revoke_all_others') }}</button>
            </div>
        </div>

        <div v-if="isLoading" class="has-text-grey">{{ $t('label.loading') }}</div>

        <div v-else-if="!sessions.length" class="notification is-light has-text-grey">
            {{ $t('message.no_sessions_yet') }}
        </div>

        <div v-else class="box">
            <div v-for="s in sessions" :key="s.id" class="is-flex is-align-items-center is-justify-content-space-between py-3" style="border-bottom:1px solid #f0f0f0">
                <div class="is-flex is-align-items-center">
                    <component :is="deviceIcon(s.user_agent)" class="mr-3" />
                    <div>
                        <p class="has-text-weight-semibold">
                            {{ masked(s.token || s.id) }}
                            <span v-if="isCurrent(s)" class="tag is-success is-light ml-2">{{ $t('message.current_session_marker') }}</span>
                        </p>
                        <p class="is-size-7 has-text-grey">{{ s.ip_address || '—' }}</p>
                        <p class="is-size-7 has-text-grey" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ s.user_agent || '—' }}</p>
                        <p class="is-size-7 has-text-grey">{{ $t('label.last_active') }}: {{ s.last_active_at ? new Date(s.last_active_at).toLocaleString() : '—' }}</p>
                    </div>
                </div>
                <div>
                    <button v-if="!isCurrent(s)" class="button is-danger is-light is-small" @click="pendingRevoke = s"><LucideTrash2 /></button>
                </div>
            </div>
        </div>

        <!-- Revoke confirmation modal -->
        <div v-if="pendingRevoke" class="modal is-active" role="dialog" aria-modal="true">
            <div class="modal-background" @click="pendingRevoke = null"></div>
            <div class="modal-card">
                <header class="modal-card-head">
                    <p class="modal-card-title">{{ $t('label.revoke') }}</p>
                    <button class="delete" aria-label="close" @click="pendingRevoke = null"></button>
                </header>
                <section class="modal-card-body">
                    {{ $t('confirmation.revoke_session') }}
                </section>
                <footer class="modal-card-foot">
                    <VueButton class="button is-danger" @click="confirmRevoke">{{ $t('label.revoke') }}</VueButton>
                    <VueButton class="button" @click="pendingRevoke = null">{{ $t('label.cancel') }}</VueButton>
                </footer>
            </div>
        </div>
    </div>
    </div>
</template>
