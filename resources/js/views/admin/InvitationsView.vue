<script setup>
    import invitationService from '@/services/invitationService'
    import { useNotify } from '@2fauth/ui'
    import { LucidePlus, LucideTrash2 } from 'lucide-vue-next'

    const { t } = useI18n()
    const notify = useNotify()

    const invitations = ref([])
    const isLoading = ref(false)
    const isSending = ref(false)
    const pendingRevoke = ref(null)

    const form = reactive({ email: '', role: 'user' })

    const roles = [
        { text: 'label.role_user', value: 'user' },
        { text: 'label.role_admin', value: 'admin' },
    ]

    onMounted(load)

    async function load() {
        isLoading.value = true
        try {
            const { data } = await invitationService.list().catch(() => ({ data: [] }))
            invitations.value = Array.isArray(data) ? data : (data.data ?? [])
        } finally {
            isLoading.value = false
        }
    }

    async function sendInvitation() {
        if (!form.email.trim()) return
        isSending.value = true
        try {
            const { data } = await invitationService.create({ email: form.email, role: form.role })
            invitations.value.push(data)
            form.email = ''
            form.role = 'user'
            notify.success({ text: t('notification.invitation_sent') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        } finally {
            isSending.value = false
        }
    }

    async function confirmRevoke() {
        if (!pendingRevoke.value) return
        const id = pendingRevoke.value.id
        pendingRevoke.value = null
        try {
            await invitationService.revoke(id)
            invitations.value = invitations.value.filter(i => i.id !== id)
            notify.success({ text: t('notification.invitation_revoked') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }
</script>

<template>
    <div class="container py-5">
        <div class="level mb-4">
            <div class="level-left">
                <h2 class="title is-3 mb-0">{{ $t('title.invitations') }}</h2>
            </div>
        </div>

        <!-- Invite form -->
        <div class="box mb-4">
            <h4 class="title is-5">{{ $t('label.invite_user') }}</h4>
            <div class="field">
                <label class="label is-size-7">{{ $t('label.email') }}</label>
                <input class="input" type="email" v-model="form.email" :placeholder="$t('placeholder.invite_email')" />
            </div>
            <div class="field">
                <label class="label is-size-7">{{ $t('label.role') }}</label>
                <div class="select">
                    <select v-model="form.role">
                        <option v-for="r in roles" :key="r.value" :value="r.value">{{ $t(r.text) }}</option>
                    </select>
                </div>
            </div>
            <div class="buttons">
                <VueButton class="button is-primary" :isLoading="isSending" :disabled="!form.email.trim()" @click="sendInvitation">
                    <LucidePlus class="mr-1" />{{ $t('label.invite_user') }}
                </VueButton>
            </div>
        </div>

        <!-- Pending invitations -->
        <div class="box">
            <h4 class="title is-5 mb-3">{{ $t('label.pending_invitations') }}</h4>

            <div v-if="isLoading" class="has-text-grey">{{ $t('label.loading') }}</div>
            <p v-else-if="!invitations.length" class="has-text-grey">{{ $t('message.no_invitations_yet') }}</p>

            <table v-else class="table is-fullwidth is-narrow">
                <thead>
                    <tr>
                        <th>{{ $t('label.email') }}</th>
                        <th>{{ $t('label.role') }}</th>
                        <th>{{ $t('label.date') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="inv in invitations" :key="inv.id">
                        <td>{{ inv.email }}</td>
                        <td><span class="tag is-light">{{ inv.role }}</span></td>
                        <td class="is-size-7 has-text-grey">{{ inv.created_at ? new Date(inv.created_at).toLocaleString() : '—' }}</td>
                        <td class="has-text-right">
                            <button class="button is-danger is-light is-small" @click="pendingRevoke = inv"><LucideTrash2 /></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Revoke confirmation modal -->
        <div v-if="pendingRevoke" class="modal is-active" role="dialog" aria-modal="true">
            <div class="modal-background" @click="pendingRevoke = null"></div>
            <div class="modal-card">
                <header class="modal-card-head">
                    <p class="modal-card-title">{{ $t('label.revoke') }}</p>
                    <button class="delete" :aria-label="$t('label.close')" @click="pendingRevoke = null"></button>
                </header>
                <section class="modal-card-body">
                    {{ $t('confirmation.revoke_invitation') }}
                </section>
                <footer class="modal-card-foot">
                    <VueButton class="button is-danger" @click="confirmRevoke">{{ $t('label.revoke') }}</VueButton>
                    <VueButton class="button" @click="pendingRevoke = null">{{ $t('label.cancel') }}</VueButton>
                </footer>
            </div>
        </div>
    </div>
</template>
