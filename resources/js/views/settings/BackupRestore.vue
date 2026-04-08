<script setup>
    import tabs from './tabs'
    import Form from '@/components/formElements/Form'
    import { useUserStore } from '@/stores/user'
    import { useBackupStore } from '@/stores/backup'
    import { useNotify, TabBar } from '@2fauth/ui'
    import { useI18n } from 'vue-i18n'
    import { useErrorHandler } from '@2fauth/stores'
    import { computed, ref, onMounted } from 'vue'
    import httpClientFactory from '@/services/httpClientFactory'

    const errorHandler = useErrorHandler()
    const { t } = useI18n()
    const $2fauth = inject('2fauth')
    const user = useUserStore()
    const backup = useBackupStore()
    const notify = useNotify()
    const router = useRouter()
    const returnTo = useStorage($2fauth.prefix + 'returnTo', 'accounts')
    const apiClient = httpClientFactory('api')

    const isExporting = ref(false)
    const isImporting = ref(false)
    const backupFile = ref(null)
    const showExportDialog = ref(false)
    const showImportDialog = ref(false)
    const exportPassword = ref('')
    const importPassword = ref('')

    // Backup preview state
    const backupMetadata = ref(null)
    const isPreviewing = ref(false)
    const conflictResolution = ref('skip')
    const importGroups = ref(true)

    const backupInfo = computed(() => backup.info)
    const encryptionStatus = ref(null)
    const encryptionEnabled = computed(() => {
        if (encryptionStatus.value) {
            return encryptionStatus.value.encryption_enabled === true
        }

        return user.encryption_version > 0
    })

    onMounted(async () => {
        const [backupResponse, encryptionResponse] = await Promise.allSettled([
            backup.fetchInfo(),
            apiClient.get('/encryption/status'),
        ])

        if (encryptionResponse.status === 'fulfilled') {
            encryptionStatus.value = encryptionResponse.value.data
        }

        if (backupResponse.status === 'rejected') {
            throw backupResponse.reason
        }
    })

    /**
     * Export encrypted backup
     */
    function exportBackup() {
        if (!encryptionEnabled.value) {
            notify.alert({ text: t('error.encryption_not_enabled') })
            return
        }
        showExportDialog.value = true
    }

    async function confirmExport() {
        if (!exportPassword.value) {
            notify.alert({ text: t('error.password_required') })
            return
        }

        isExporting.value = true
        try {
            await backup.exportBackup(exportPassword.value)
            notify.success({ text: t('notification.backup_exported') })
            showExportDialog.value = false
            exportPassword.value = ''
            await backup.fetchInfo()
        } catch (error) {
            if (error.response?.status === 400) {
                notify.alert({ text: error.response.data.message })
            } else {
                errorHandler.show(error)
            }
        } finally {
            isExporting.value = false
        }
    }

    /**
     * Import encrypted backup
     */
    async function selectBackupFile(event) {
        const file = event.target.files[0]
        if (!file) return

        backupFile.value = file
        isPreviewing.value = true

        try {
            const metadata = await backup.getBackupMetadata(file)
            backupMetadata.value = metadata
        } catch {
            backupMetadata.value = null
        } finally {
            isPreviewing.value = false
            showImportDialog.value = true
        }
    }

    async function confirmImport() {
        if (!backupFile.value) {
            notify.alert({ text: t('error.file_required') })
            return
        }

        isImporting.value = true
        try {
            const result = await backup.importBackup(
                backupFile.value,
                importPassword.value,
                conflictResolution.value,
                importGroups.value
            )

            notify.success({
                text: t('notification.backup_imported', {
                    imported: result.imported || result.imported_count || 0,
                })
            })

            showImportDialog.value = false
            importPassword.value = ''
            backupFile.value = null
            backupMetadata.value = null
            await backup.fetchInfo()
        } catch (error) {
            if (error.response?.status === 422 || error.response?.status === 400) {
                notify.alert({ text: error.response.data.message })
            } else {
                errorHandler.show(error)
            }
        } finally {
            isImporting.value = false
        }
    }

    function cancelExport() {
        showExportDialog.value = false
        exportPassword.value = ''
    }

    function cancelImport() {
        showImportDialog.value = false
        importPassword.value = ''
        backupFile.value = null
        backupMetadata.value = null
    }

    onBeforeRouteLeave((to) => {
        if (!to.name.startsWith('settings.') && to.name === 'login') {
            returnTo.value = to.name
        }
    })
</script>

<template>
    <StackLayout>
        <template #header>
            <TabBar :tabs="tabs" :active-tab="'settings.backup'" @tab-selected="(to) => router.push({ name: to })" />
        </template>
        <template #content>
            <FormWrapper>
                <form>
                    <!-- Export Section -->
                    <div class="block">
                        <h4 class="title is-4">{{ $t('settings.backup.export_title') }}</h4>
                        <p class="block">{{ $t('settings.backup.export_description') }}</p>

                        <!-- Not encrypted: show warning -->
                        <div v-if="!encryptionEnabled" class="notification is-warning">
                            {{ $t('settings.backup.encryption_required') }}
                            <p class="mt-3">
                                <router-link :to="{ name: 'settings.encryption' }" class="button is-small is-info">
                                    {{ $t('settings.backup.enable_encryption') }}
                                </router-link>
                            </p>
                        </div>

                        <!-- Encrypted: show export button -->
                        <div v-else class="block">
                            <button
                                type="button"
                                class="button is-primary"
                                :class="{ 'is-loading': isExporting }"
                                @click="exportBackup"
                            >
                                {{ $t('settings.backup.export_button') }}
                            </button>

                            <!-- Backup info -->
                            <div v-if="backupInfo.hasBackup" class="notification is-info is-light mt-3">
                                <p>
                                    <strong>{{ $t('settings.backup.last_backup') }}:</strong>
                                    {{ new Date(backupInfo.last_backup_at || backupInfo.lastBackupAt).toLocaleString() }}
                                </p>
                                <p v-if="backupInfo.days_since_backup || backupInfo.daysSinceBackup" class="is-size-7 mt-1">
                                    {{ $t('settings.backup.days_ago', { days: backupInfo.days_since_backup || backupInfo.daysSinceBackup }) }}
                                </p>
                            </div>

                            <div v-else class="notification is-warning is-light mt-3">
                                <p>{{ $t('settings.backup.no_backup_yet') }}</p>
                            </div>
                        </div>
                    </div>

                    <hr />

                    <!-- Import Section -->
                    <div class="block">
                        <h4 class="title is-4">{{ $t('settings.backup.import_title') }}</h4>
                        <p class="block">{{ $t('settings.backup.import_description') }}</p>

                        <!-- File upload -->
                        <div class="file has-name mt-3">
                            <label class="file-label">
                                <input
                                    class="file-input"
                                    type="file"
                                    accept=".vault,.json"
                                    @change="selectBackupFile"
                                    :disabled="isImporting"
                                />
                                <span class="file-cta">
                                    <span class="file-icon">
                                        <i class="fas fa-upload"></i>
                                    </span>
                                    <span class="file-label">
                                        {{ $t('settings.backup.choose_file') }}
                                    </span>
                                </span>
                                <span class="file-name" v-if="backupFile">
                                    {{ backupFile.name }}
                                </span>
                            </label>
                        </div>

                        <!-- Loading preview -->
                        <div v-if="isPreviewing" class="has-text-centered py-3">
                            <span class="loader"></span>
                            <p class="mt-2">{{ $t('settings.backup.previewing') }}</p>
                        </div>
                    </div>
                </form>
            </FormWrapper>

            <!-- Export Dialog -->
            <div class="modal" :class="{ 'is-active': showExportDialog }">
                <div class="modal-background" @click="cancelExport"></div>
                <div class="modal-card">
                    <header class="modal-card-head">
                        <p class="modal-card-title">{{ $t('settings.backup.export_dialog_title') }}</p>
                        <button class="delete" @click="cancelExport" aria-label="close"></button>
                    </header>
                    <section class="modal-card-body">
                        <div class="field">
                            <label class="label">{{ $t('settings.backup.master_password') }}</label>
                            <div class="control">
                                <input
                                    class="input"
                                    type="password"
                                    v-model="exportPassword"
                                    :placeholder="t('settings.backup.password_placeholder')"
                                />
                            </div>
                            <p class="help">{{ $t('settings.backup.password_help') }}</p>
                        </div>
                    </section>
                    <footer class="modal-card-foot">
                        <button class="button is-primary" @click="confirmExport" :disabled="isExporting">
                            <span class="icon" v-if="isExporting">
                                <i class="fas fa-spinner fa-pulse"></i>
                            </span>
                            <span>{{ $t('label.confirm') }}</span>
                        </button>
                        <button class="button" @click="cancelExport">{{ $t('label.cancel') }}</button>
                    </footer>
                </div>
            </div>

            <!-- Import Dialog -->
            <div class="modal" :class="{ 'is-active': showImportDialog }">
                <div class="modal-background" @click="cancelImport"></div>
                <div class="modal-card">
                    <header class="modal-card-head">
                        <p class="modal-card-title">{{ $t('settings.backup.import_dialog_title') }}</p>
                        <button class="delete" @click="cancelImport" aria-label="close"></button>
                    </header>
                    <section class="modal-card-body">
                        <!-- Backup Preview -->
                        <div v-if="backupMetadata" class="notification is-info is-light mb-4">
                            <p class="has-text-weight-semibold mb-2">{{ $t('settings.backup.preview_title') }}</p>
                            <ul>
                                <li>{{ $t('settings.backup.preview_format') }}: <strong>{{ backupMetadata.format || 'unknown' }}</strong></li>
                                <li>{{ $t('settings.backup.preview_accounts') }}: <strong>{{ backupMetadata.account_count || backupMetadata.accountCount || 0 }}</strong></li>
                                <li v-if="backupMetadata.group_count || backupMetadata.groupCount">
                                    {{ $t('settings.backup.preview_groups') }}: <strong>{{ backupMetadata.group_count || backupMetadata.groupCount }}</strong>
                                </li>
                                <li v-if="backupMetadata.version">{{ $t('settings.backup.preview_version') }}: <strong>{{ backupMetadata.version }}</strong></li>
                                <li>
                                    {{ $t('settings.backup.preview_encrypted') }}:
                                    <strong :class="backupMetadata.encrypted ? 'has-text-success' : 'has-text-warning'">
                                        {{ backupMetadata.encrypted ? $t('label.yes') : $t('label.no') }}
                                    </strong>
                                </li>
                                <li v-if="backupMetadata.exported_at || backupMetadata.exportedAt">
                                    {{ $t('settings.backup.preview_exported_at') }}: <strong>{{ new Date(backupMetadata.exported_at || backupMetadata.exportedAt).toLocaleString() }}</strong>
                                </li>
                            </ul>
                        </div>

                        <!-- Password -->
                        <div class="field">
                            <label class="label">{{ $t('settings.backup.master_password') }}</label>
                            <div class="control">
                                <input
                                    class="input"
                                    type="password"
                                    v-model="importPassword"
                                    :placeholder="t('settings.backup.password_placeholder')"
                                />
                            </div>
                            <p class="help">{{ $t('settings.backup.password_help') }}</p>
                        </div>

                        <!-- Conflict Resolution -->
                        <div class="field">
                            <label class="label">{{ $t('settings.backup.conflict_resolution') }}</label>
                            <div class="control">
                                <label class="radio">
                                    <input type="radio" v-model="conflictResolution" value="skip" />
                                    {{ $t('settings.backup.conflict_skip') }}
                                </label>
                                <label class="radio">
                                    <input type="radio" v-model="conflictResolution" value="replace" />
                                    {{ $t('settings.backup.conflict_replace') }}
                                </label>
                                <label class="radio">
                                    <input type="radio" v-model="conflictResolution" value="rename" />
                                    {{ $t('settings.backup.conflict_rename') }}
                                </label>
                            </div>
                            <p class="help" v-if="conflictResolution === 'replace'">
                                <strong class="has-text-danger">{{ $t('settings.backup.replace_warning') }}</strong>
                            </p>
                        </div>

                        <!-- Import Groups Toggle -->
                        <div class="field" v-if="backupMetadata && (backupMetadata.group_count || backupMetadata.groupCount)">
                            <label class="checkbox">
                                <input type="checkbox" v-model="importGroups" />
                                {{ $t('settings.backup.import_groups') }}
                            </label>
                            <p class="help">{{ $t('settings.backup.import_groups.help') }}</p>
                        </div>
                    </section>
                    <footer class="modal-card-foot">
                        <button class="button is-primary" @click="confirmImport" :disabled="isImporting">
                            <span class="icon" v-if="isImporting">
                                <i class="fas fa-spinner fa-pulse"></i>
                            </span>
                            <span>{{ $t('label.confirm') }}</span>
                        </button>
                        <button class="button" @click="cancelImport">{{ $t('label.cancel') }}</button>
                    </footer>
                </div>
            </div>
        </template>
        <template #footer>
            <VueFooter>
                <template #default>
                    <NavigationButton action="close" @closed="router.push({ name: returnTo })" :current-page-title="$t('title.settings.backup')" />
                </template>
            </VueFooter>
        </template>
    </StackLayout>
</template>
