<script setup>
    import tabs from './tabs'
    import emergencyService from '@/services/emergencyService'
    import { useNotify, TabBar } from '@2fauth/ui'

    const { t } = useI18n()
    const notify = useNotify()
    const router = useRouter()

    const contacts     = ref([])
    const pendingReqs  = ref([])
    const contactsForMe = ref([])

    const newEmail     = ref('')
    const newWaitDays  = ref(30)
    const newAccess    = ref('view_only')
    const isAdding     = ref(false)

    const WAIT_OPTIONS = [7, 14, 30, 60, 90]

    onMounted(async () => {
        await Promise.all([loadContacts(), loadPending(), loadForMe()])
    })

    async function loadContacts() {
        const { data } = await emergencyService.getContacts().catch(() => ({ data: [] }))
        contacts.value = data
    }
    async function loadPending() {
        const { data } = await emergencyService.getPendingRequests().catch(() => ({ data: [] }))
        pendingReqs.value = data
    }
    async function loadForMe() {
        const { data } = await emergencyService.getContactsForMe().catch(() => ({ data: [] }))
        contactsForMe.value = data
    }

    async function addContact() {
        if (!newEmail.value.trim()) return
        isAdding.value = true
        try {
            const { data } = await emergencyService.addContact({ email: newEmail.value.trim(), wait_days: newWaitDays.value, access_type: newAccess.value })
            contacts.value.push(data)
            newEmail.value = ''
            notify.success({ text: t('notification.emergency_contact_added') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        } finally {
            isAdding.value = false
        }
    }

    async function revokeContact(contact) {
        if (!confirm(t('confirmation.revoke_emergency_contact', { email: contact.email }))) return
        try {
            await emergencyService.revokeContact(contact.id)
            contacts.value = contacts.value.filter(c => c.id !== contact.id)
            notify.success({ text: t('notification.emergency_contact_revoked') })
        } catch {
            notify.alert({ text: t('error.unknown') })
        }
    }

    async function approveRequest(req) {
        try {
            await emergencyService.approveRequest(req.id)
            pendingReqs.value = pendingReqs.value.filter(r => r.id !== req.id)
            notify.success({ text: t('notification.emergency_access_approved') })
        } catch {
            notify.alert({ text: t('error.unknown') })
        }
    }

    async function denyRequest(req) {
        try {
            await emergencyService.denyRequest(req.id)
            pendingReqs.value = pendingReqs.value.filter(r => r.id !== req.id)
            notify.success({ text: t('notification.emergency_access_denied') })
        } catch {
            notify.alert({ text: t('error.unknown') })
        }
    }

    async function requestMyAccess(contact) {
        try {
            await emergencyService.requestAccess(contact.id)
            notify.success({ text: t('notification.emergency_access_requested') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }

    function statusClass(status) {
        return { pending: 'is-warning', confirmed: 'is-info', active: 'is-success', revoked: 'is-danger' }[status] ?? 'is-light'
    }
</script>

<template>
    <div>
        <TabBar :tabs="tabs" :active-tab="'settings.emergency'" @tab-selected="(to) => router.push({ name: to })" />
    <div class="container py-5">
        <h2 class="title is-3 mb-2">{{ $t('title.emergency_access') }}</h2>
        <p class="is-size-7 has-text-grey mb-5">{{ $t('message.emergency_access_desc') }}</p>

        <!-- Pending requests for me to approve -->
        <div v-if="pendingReqs.length" class="notification is-warning mb-4">
            <p class="has-text-weight-semibold mb-2">{{ $t('message.pending_emergency_requests', { n: pendingReqs.length }) }}</p>
            <div v-for="req in pendingReqs" :key="req.id" class="is-flex is-align-items-center mb-2">
                <span class="mr-3">{{ req.requester?.name }} ({{ req.contact?.email }})</span>
                <div class="buttons are-small mb-0">
                    <VueButton class="button is-success" @click="approveRequest(req)">{{ $t('label.approve') }}</VueButton>
                    <VueButton class="button is-danger" @click="denyRequest(req)">{{ $t('label.deny') }}</VueButton>
                </div>
            </div>
        </div>

        <!-- My designated contacts -->
        <div class="box mb-4">
            <h4 class="title is-5">{{ $t('title.my_emergency_contacts') }}</h4>
            <p v-if="!contacts.length" class="has-text-grey is-size-7 mb-3">{{ $t('message.no_emergency_contacts') }}</p>
            <div v-for="c in contacts" :key="c.id" class="is-flex is-align-items-center is-justify-content-space-between mb-2">
                <div>
                    <span class="has-text-weight-semibold">{{ c.email }}</span>
                    <span class="tag ml-2 is-size-7" :class="statusClass(c.status)">{{ c.status }}</span>
                    <span class="is-size-7 has-text-grey ml-2">{{ c.wait_days }} days · {{ c.access_type }}</span>
                </div>
                <VueButton class="button is-small is-danger is-light" @click="revokeContact(c)">{{ $t('label.revoke') }}</VueButton>
            </div>

            <!-- Add new contact -->
            <hr />
            <p class="has-text-weight-semibold is-size-7 mb-2">{{ $t('label.add_emergency_contact') }}</p>
            <div class="field is-grouped is-flex-wrap-wrap">
                <div class="control is-expanded">
                    <input class="input is-small" type="email" v-model="newEmail" :placeholder="$t('field.trusted_email')" />
                </div>
                <div class="control">
                    <div class="select is-small">
                        <select v-model="newWaitDays">
                            <option v-for="d in WAIT_OPTIONS" :key="d" :value="d">{{ d }} {{ $t('label.days') }}</option>
                        </select>
                    </div>
                </div>
                <div class="control">
                    <div class="select is-small">
                        <select v-model="newAccess">
                            <option value="view_only">{{ $t('label.view_only') }}</option>
                            <option value="full_access">{{ $t('label.full_access') }}</option>
                        </select>
                    </div>
                </div>
                <div class="control">
                    <VueButton class="button is-small is-primary" :isLoading="isAdding" @click="addContact">{{ $t('label.add') }}</VueButton>
                </div>
            </div>
        </div>

        <!-- Contacts where I'm trusted -->
        <div v-if="contactsForMe.length" class="box">
            <h4 class="title is-5">{{ $t('title.emergency_contacts_for_me') }}</h4>
            <div v-for="c in contactsForMe" :key="c.id" class="is-flex is-align-items-center is-justify-content-space-between mb-2">
                <span>{{ c.owner?.name }} ({{ c.owner?.email }}) — <em class="is-size-7">{{ c.access_type }}</em></span>
                <VueButton v-if="c.status === 'confirmed'" class="button is-small is-warning" @click="requestMyAccess(c)">
                    {{ $t('label.request_emergency_access') }}
                </VueButton>
                <span v-else-if="c.status === 'active'" class="tag is-success">{{ $t('label.access_granted') }}</span>
            </div>
        </div>
    </div>
    </div>
</template>
