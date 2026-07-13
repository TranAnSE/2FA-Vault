<script setup>
    import tabs from './tabs'
    import vaultManagerService from '@/services/vaultManagerService'
    import { useNotify, TabBar } from '@2fauth/ui'

    const { t } = useI18n()
    const notify = useNotify()
    const router = useRouter()

    const vaults     = ref([])
    const isLoading  = ref(false)
    const newName    = ref('')
    const isCreating = ref(false)
    const editingId  = ref(null)
    const editName   = ref('')

    onMounted(load)

    async function load() {
        isLoading.value = true
        try {
            const { data } = await vaultManagerService.getAll()
            vaults.value = data
        } catch {
            notify.alert({ text: t('error.data_cannot_be_refreshed_from_server') })
        } finally {
            isLoading.value = false
        }
    }

    async function createVault() {
        if (!newName.value.trim()) return
        isCreating.value = true
        try {
            const { data } = await vaultManagerService.create({ name: newName.value.trim() })
            vaults.value.push(data)
            newName.value = ''
            notify.success({ text: t('notification.vault_created') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        } finally {
            isCreating.value = false
        }
    }

    function startEdit(vault) {
        editingId.value = vault.id
        editName.value  = vault.name
    }

    async function saveEdit(vault) {
        try {
            const { data } = await vaultManagerService.rename(vault.id, { name: editName.value })
            const idx = vaults.value.findIndex(v => v.id === data.id)
            if (idx !== -1) vaults.value[idx] = data
            editingId.value = null
            notify.success({ text: t('notification.vault_renamed') })
        } catch {
            notify.alert({ text: t('error.unknown') })
        }
    }

    async function deleteVault(vault) {
        if (!confirm(t('confirmation.delete_vault', { name: vault.name }))) return
        try {
            await vaultManagerService.delete(vault.id)
            vaults.value = vaults.value.filter(v => v.id !== vault.id)
            notify.success({ text: t('notification.vault_deleted') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }

    async function lockVault(vault) {
        try {
            await vaultManagerService.lock(vault.id)
            const v = vaults.value.find(v => v.id === vault.id)
            if (v) v.is_locked = true
            notify.success({ text: t('notification.vault_locked') })
        } catch {
            notify.alert({ text: t('error.unknown') })
        }
    }
</script>

<template>
    <div>
        <TabBar :tabs="tabs" :active-tab="'settings.vaults'" @tab-selected="(to) => router.push({ name: to })" />
    <div class="container py-5">
        <h2 class="title is-3 mb-2">{{ $t('title.vaults') }}</h2>
        <p class="is-size-7 has-text-grey mb-5">{{ $t('message.vaults_desc') }}</p>

        <!-- Create vault -->
        <div class="box mb-4">
            <h4 class="title is-5">{{ $t('label.create_vault') }}</h4>
            <div class="field has-addons">
                <div class="control is-expanded">
                    <input class="input" type="text" v-model="newName" :placeholder="$t('field.vault_name')" @keydown.enter="createVault" />
                </div>
                <div class="control">
                    <VueButton class="button is-primary" :isLoading="isCreating" @click="createVault">{{ $t('label.create') }}</VueButton>
                </div>
            </div>
        </div>

        <!-- Vault list -->
        <div v-if="isLoading" class="has-text-centered py-4"><span class="loader"></span></div>
        <div v-else class="box">
            <p v-if="!vaults.length" class="has-text-grey">{{ $t('message.no_vaults_yet') }}</p>
            <div v-for="vault in vaults" :key="vault.id" class="is-flex is-align-items-center is-justify-content-space-between mb-3">
                <div class="is-flex-grow-1">
                    <template v-if="editingId === vault.id">
                        <input class="input is-small" type="text" v-model="editName" @keydown.enter="saveEdit(vault)" style="max-width:200px" />
                    </template>
                    <template v-else>
                        <span class="has-text-weight-semibold">{{ vault.name }}</span>
                        <span v-if="vault.is_default" class="tag is-info is-light ml-2 is-size-7">{{ $t('label.default') }}</span>
                        <span class="tag ml-2 is-size-7" :class="vault.is_locked ? 'is-warning' : 'is-success'">
                            {{ vault.is_locked ? $t('label.locked') : $t('label.unlocked') }}
                        </span>
                        <span class="is-size-7 has-text-grey ml-2">{{ vault.accounts_count ?? 0 }} accounts</span>
                    </template>
                </div>
                <div class="buttons are-small mb-0">
                    <template v-if="editingId === vault.id">
                        <VueButton class="button is-success" @click="saveEdit(vault)">{{ $t('label.save') }}</VueButton>
                        <VueButton class="button" @click="editingId = null">{{ $t('label.cancel') }}</VueButton>
                    </template>
                    <template v-else>
                        <VueButton class="button is-info is-light" @click="startEdit(vault)">{{ $t('label.rename') }}</VueButton>
                        <VueButton v-if="!vault.is_locked" class="button is-warning is-light" @click="lockVault(vault)">{{ $t('label.lock') }}</VueButton>
                        <VueButton v-if="!vault.is_default" class="button is-danger is-light" @click="deleteVault(vault)">{{ $t('label.delete') }}</VueButton>
                    </template>
                </div>
            </div>
        </div>
    </div>
    </div>
</template>
