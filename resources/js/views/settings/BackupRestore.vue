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
    import backupSnapshotService from '@/services/backupSnapshotService'

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

    // Import result state (warnings + one-click cleanup of undecryptable
    // just-imported accounts, C3)
    const legacyFormatWarning = ref(false)
    const keyMismatchWarning = ref(false)
    const undecryptableAccountIds = ref([])
    const isDeletingImported = ref(false)

    // Snapshots state (v1.4.0 server-side snapshot store)
    const snapshots = ref([])
    const isLoadingSnapshots = ref(false)
    const newSnapshotLabel = ref('')
    const isCreatingSnapshot = ref(false)
    const deleteTarget = ref(null)
    const isDeletingSnapshot = ref(false)

    // Restore wizard state. The SERVER token from the dry-run is the real
    // gate; the wizard only makes the diff impossible to skip (step 3's
    // confirm input renders only after the step-2 diff has rendered).
    const showRestoreWizard = ref(false)
    const wizardSnapshot = ref(null)
    const wizardMode = ref('merge')
    const wizardStep = ref(1)
    const wizardDiff = ref(null)
    const wizardConfirmText = ref('')
    const isDryRunning = ref(false)
    const isRestoring = ref(false)
    const wizardError = ref('')
    const wizardResult = ref(null)

    const replaceConfirmText = computed(() => wizardConfirmText.value === 'RESTORE')
    const canConfirmRestore = computed(() => (wizardMode.value === 'replace' ? replaceConfirmText.value : true))

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

        await loadSnapshots()
    })

    /**
     * Snapshots (v1.4.0)
     */
    async function loadSnapshots() {
        isLoadingSnapshots.value = true
        try {
            const { data } = await backupSnapshotService.list()
            snapshots.value = Array.isArray(data) ? data : (data.data ?? [])
        } catch (error) {
            errorHandler.show(error)
        } finally {
            isLoadingSnapshots.value = false
        }
    }

    async function createSnapshot() {
        isCreatingSnapshot.value = true
        try {
            await backupSnapshotService.create(
                newSnapshotLabel.value.trim() ? { label: newSnapshotLabel.value.trim() } : {}
            )
            newSnapshotLabel.value = ''
            await loadSnapshots()
            notify.success({ text: t('settings.snapshots.created_notification') })
        } catch (error) {
            if (error.response?.status === 422) {
                notify.alert({ text: error.response.data.message })
            } else {
                errorHandler.show(error)
            }
        } finally {
            isCreatingSnapshot.value = false
        }
    }

    function askDeleteSnapshot(snapshot) {
        deleteTarget.value = snapshot
    }

    function cancelDeleteSnapshot() {
        deleteTarget.value = null
    }

    async function confirmDeleteSnapshot() {
        if (!deleteTarget.value) return

        isDeletingSnapshot.value = true
        try {
            await backupSnapshotService.remove(deleteTarget.value.id)
            notify.success({ text: t('settings.snapshots.deleted_notification') })
            deleteTarget.value = null
            await loadSnapshots()
        } catch (error) {
            errorHandler.show(error)
        } finally {
            isDeletingSnapshot.value = false
        }
    }

    function sourceBadgeClass(source) {
        return {
            manual: 'is-info is-light',
            pre_restore: 'is-warning is-light',
            scheduled: 'is-success is-light',
        }[source] ?? 'is-light'
    }

    function formatSnapshotSize(bytes) {
        const size = Number(bytes) || 0
        if (size < 1024) return `${size} B`
        if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`

        return `${(size / (1024 * 1024)).toFixed(1)} MB`
    }

    function openRestoreWizard(snapshot) {
        wizardSnapshot.value = snapshot
        wizardMode.value = 'merge'
        wizardStep.value = 1
        wizardDiff.value = null
        wizardConfirmText.value = ''
        wizardError.value = ''
        wizardResult.value = null
        showRestoreWizard.value = true
    }

    function cancelRestoreWizard() {
        if (isRestoring.value || isDryRunning.value) return
        showRestoreWizard.value = false
    }

    /**
     * Step 1 → 2: compute the dry-run diff and capture the server token.
     */
    async function runWizardDiff() {
        if (!wizardSnapshot.value) return

        isDryRunning.value = true
        wizardError.value = ''
        try {
            const { data } = await backupSnapshotService.dryRun(wizardSnapshot.value.id, wizardMode.value)
            wizardDiff.value = data
            wizardStep.value = 2
        } catch (error) {
            if (error.response?.status === 422 && error.response.data?.unreadable_reason) {
                wizardError.value = t('settings.snapshots.unreadable_snapshot')
            } else {
                errorHandler.show(error)
            }
        } finally {
            isDryRunning.value = false
        }
    }

    function proceedToConfirm() {
        wizardStep.value = 3
    }

    function backToDiff() {
        wizardStep.value = 2
        wizardConfirmText.value = ''
    }

    /**
     * Step 3: run the restore. A 409 (vault drifted since the diff) or an
     * expired/invalid token sends the user back to step 2 with a fresh
     * diff and a visible reason instead of a dead end.
     */
    async function confirmRestore() {
        if (!canConfirmRestore.value || isRestoring.value) return

        isRestoring.value = true
        wizardError.value = ''
        try {
            const { data } = await backupSnapshotService.restore(wizardSnapshot.value.id, {
                mode: wizardMode.value,
                token: wizardDiff.value.token,
                ...(wizardMode.value === 'replace' ? { confirm: 'RESTORE' } : {}),
            })
            wizardResult.value = data
            // Refresh BEFORE showing the result step: while isRestoring is
            // true the wizard refuses to close, so the result step must only
            // render once the restore is fully finished (including reload).
            await loadSnapshots()
            wizardStep.value = 4
        } catch (error) {
            if (error.response?.status === 409 || error.response?.status === 422) {
                wizardConfirmText.value = ''
                // Re-diff first (fresh token + step 2), THEN set the reason:
                // runWizardDiff resets wizardError, so setting it beforehand
                // would wipe the message and leave the bounce unexplained.
                await runWizardDiff()
                if (!wizardError.value) {
                    wizardError.value =
                        error.response.status === 409
                            ? t('settings.snapshots.state_changed')
                            : t('settings.snapshots.token_expired')
                }
            } else {
                errorHandler.show(error)
            }
        } finally {
            isRestoring.value = false
        }
    }

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
            // Invalid/unreadable file: stay on the page, tell the user in
            // place and let the server-side confirm reject the import.
            backupMetadata.value = null
            notify.alert({ text: t('error.invalid_backup_file') })
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
            // Format 2 files are decrypted client-side before upload (the
            // password never leaves the browser); legacy files upload as-is.
            const result = await backup.importBackup(
                backupFile.value,
                importPassword.value,
                conflictResolution.value,
                importGroups.value
            )

            notify.success({
                text: t('notification.backup_imported', {
                    imported: result.imported_count || 0,
                })
            })

            legacyFormatWarning.value = result.legacy_format_warning === true
            keyMismatchWarning.value = result.key_mismatch_warning === true
            undecryptableAccountIds.value = keyMismatchWarning.value
                ? (result.imported_account_ids || [])
                : []

            showImportDialog.value = false
            importPassword.value = ''
            backupFile.value = null
            backupMetadata.value = null
            await backup.fetchInfo()
        } catch (error) {
            if (error.response?.status === 422 || error.response?.status === 400) {
                notify.alert({ text: error.response.data.message })
            } else if (error.response === undefined && error instanceof Error && error.message) {
                // Client-side failures (e.g. wrong v2 password): tell the user
                // in place instead of bouncing to the global error page.
                notify.alert({ text: error.message })
            } else {
                errorHandler.show(error)
            }
        } finally {
            isImporting.value = false
        }
    }

    /**
     * One-click delete of just-imported undecryptable accounts (C3)
     */
    async function deleteImportedAccounts() {
        if (undecryptableAccountIds.value.length === 0) return

        isDeletingImported.value = true
        try {
            await backup.deleteImportedAccounts(undecryptableAccountIds.value)
            notify.success({ text: t('notification.accounts_deleted') })
            undecryptableAccountIds.value = []
            keyMismatchWarning.value = false
        } catch (error) {
            errorHandler.show(error)
        } finally {
            isDeletingImported.value = false
        }
    }

    function dismissImportWarnings() {
        legacyFormatWarning.value = false
        keyMismatchWarning.value = false
        undecryptableAccountIds.value = []
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
                            <div v-if="backupInfo.has_backup" class="notification is-info is-light mt-3">
                                <p>
                                    <strong>{{ $t('settings.backup.last_backup') }}:</strong>
                                    {{ new Date(backupInfo.last_backup_at).toLocaleString() }}
                                </p>
                                <p v-if="backupInfo.days_since_backup" class="is-size-7 mt-1">
                                    {{ $t('settings.backup.days_ago', { days: backupInfo.days_since_backup }) }}
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

                        <!-- Post-import warnings (C3 / RT4) -->
                        <div v-if="legacyFormatWarning" class="notification is-warning is-light mt-3">
                            {{ $t('settings.backup.warning_legacy_format') }}
                            <p class="mt-2">
                                <button type="button" class="button is-small" @click="dismissImportWarnings">
                                    {{ $t('label.close') }}
                                </button>
                            </p>
                        </div>

                        <div v-if="keyMismatchWarning" class="notification is-warning is-light mt-3">
                            {{ $t('settings.backup.warning_key_mismatch') }}
                            <p class="mt-2">
                                <button
                                    type="button"
                                    class="button is-small is-danger"
                                    :class="{ 'is-loading': isDeletingImported }"
                                    :disabled="isDeletingImported || undecryptableAccountIds.length === 0"
                                    @click="deleteImportedAccounts"
                                >
                                    {{ $t('settings.backup.delete_imported') }}
                                </button>
                            </p>
                        </div>
                    </div>

                    <hr />

                    <!-- Snapshots Section (v1.4.0) -->
                    <div class="block" data-testid="snapshots-panel">
                        <h4 class="title is-4">{{ $t('settings.snapshots.title') }}</h4>
                        <p class="block">{{ $t('settings.snapshots.description') }}</p>

                        <!-- Create snapshot -->
                        <div class="field is-grouped">
                            <div class="control is-expanded">
                                <input
                                    class="input"
                                    type="text"
                                    v-model="newSnapshotLabel"
                                    :placeholder="$t('settings.snapshots.label_placeholder')"
                                    maxlength="100"
                                    data-testid="snapshot-label-input"
                                />
                            </div>
                            <div class="control">
                                <button
                                    type="button"
                                    class="button is-primary"
                                    :class="{ 'is-loading': isCreatingSnapshot }"
                                    :disabled="isCreatingSnapshot"
                                    @click="createSnapshot"
                                    data-testid="snapshot-create"
                                >
                                    {{ $t('settings.snapshots.create_button') }}
                                </button>
                            </div>
                        </div>

                        <!-- Loading -->
                        <div v-if="isLoadingSnapshots" class="has-text-centered py-3">
                            <span class="loader"></span>
                        </div>

                        <!-- Empty state -->
                        <p v-else-if="snapshots.length === 0" class="has-text-grey" data-testid="snapshots-empty">
                            {{ $t('settings.snapshots.empty_state') }}
                        </p>

                        <!-- Snapshot list -->
                        <div v-else data-testid="snapshots-list">
                            <div
                                v-for="snapshot in snapshots"
                                :key="snapshot.id"
                                class="is-flex is-align-items-center is-justify-content-space-between py-3"
                                style="border-bottom: 1px solid #f0f0f0"
                                data-testid="snapshot-row"
                            >
                                <div>
                                    <span class="has-text-weight-semibold">{{ snapshot.label || $t('settings.snapshots.unlabeled') }}</span>
                                    <span class="tag ml-2" :class="sourceBadgeClass(snapshot.source)" :data-testid="`snapshot-source-${snapshot.source}`">
                                        {{ $t(`settings.snapshots.source_${snapshot.source}`) }}
                                    </span>
                                    <p class="is-size-7 has-text-grey mt-1">
                                        {{ $t('settings.snapshots.counts', { accounts: snapshot.accounts_count, groups: snapshot.groups_count }) }}
                                        &middot; {{ formatSnapshotSize(snapshot.size_bytes) }}
                                        &middot; {{ new Date(snapshot.created_at).toLocaleString() }}
                                    </p>
                                    <p v-if="snapshot.unreadable_reason" class="is-size-7 has-text-danger mt-1">
                                        {{ $t('settings.snapshots.unreadable') }}
                                    </p>
                                    <p v-else-if="snapshot.salt_changed_since_snapshot" class="is-size-7 has-text-warning mt-1">
                                        {{ $t('settings.snapshots.salt_changed') }}
                                    </p>
                                </div>
                                <div class="buttons mb-0">
                                    <button
                                        type="button"
                                        class="button is-small is-info"
                                        :disabled="!!snapshot.unreadable_reason"
                                        @click="openRestoreWizard(snapshot)"
                                        data-testid="snapshot-restore"
                                    >
                                        {{ $t('settings.snapshots.restore_button') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="button is-small is-danger is-light"
                                        @click="askDeleteSnapshot(snapshot)"
                                        data-testid="snapshot-delete"
                                    >
                                        {{ $t('settings.snapshots.delete_button') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </FormWrapper>

            <!-- Export Dialog -->
            <div class="modal" :class="{ 'is-active': showExportDialog }" role="dialog" aria-modal="true" aria-labelledby="export-dialog-title" @keydown.escape="cancelExport">
                <div class="modal-background" @click="cancelExport" aria-hidden="true"></div>
                <div class="modal-card">
                    <header class="modal-card-head">
                        <p class="modal-card-title" id="export-dialog-title">{{ $t('settings.backup.export_dialog_title') }}</p>
                        <button class="delete" @click="cancelExport" :aria-label="$t('label.close')"></button>
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
            <div class="modal" :class="{ 'is-active': showImportDialog }" role="dialog" aria-modal="true" aria-labelledby="import-dialog-title" @keydown.escape="cancelImport">
                <div class="modal-background" @click="cancelImport" aria-hidden="true"></div>
                <div class="modal-card">
                    <header class="modal-card-head">
                        <p class="modal-card-title" id="import-dialog-title">{{ $t('settings.backup.import_dialog_title') }}</p>
                        <button class="delete" @click="cancelImport" :aria-label="$t('label.close')"></button>
                    </header>
                    <section class="modal-card-body">
                        <!-- Backup Preview -->
                        <div v-if="backupMetadata" class="notification is-info is-light mb-4">
                            <p class="has-text-weight-semibold mb-2">{{ $t('settings.backup.preview_title') }}</p>
                            <ul>
                                <li>{{ $t('settings.backup.preview_format') }}: <strong>{{ backupMetadata.format || 'unknown' }}</strong></li>
                                <li v-if="backupMetadata.requires_decryption" class="has-text-weight-semibold">
                                    {{ $t('settings.backup.preview_requires_decryption') }}
                                </li>
                                <li v-else>{{ $t('settings.backup.preview_accounts') }}: <strong>{{ backupMetadata.account_count || 0 }}</strong></li>
                                <li v-if="backupMetadata.group_count">
                                    {{ $t('settings.backup.preview_groups') }}: <strong>{{ backupMetadata.group_count }}</strong>
                                </li>
                                <li v-if="backupMetadata.version">{{ $t('settings.backup.preview_version') }}: <strong>{{ backupMetadata.version }}</strong></li>
                                <li>
                                    {{ $t('settings.backup.preview_encrypted') }}:
                                    <strong :class="backupMetadata.encrypted ? 'has-text-success' : 'has-text-warning'">
                                        {{ backupMetadata.encrypted ? $t('label.yes') : $t('label.no') }}
                                    </strong>
                                </li>
                                <li v-if="backupMetadata.exported_at">
                                    {{ $t('settings.backup.preview_exported_at') }}: <strong>{{ new Date(backupMetadata.exported_at).toLocaleString() }}</strong>
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
                        <div class="field" v-if="backupMetadata && backupMetadata.group_count">
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

            <!-- Snapshot delete confirm -->
            <div class="modal" :class="{ 'is-active': !!deleteTarget }" role="dialog" aria-modal="true" aria-labelledby="snapshot-delete-title">
                <div class="modal-background" @click="cancelDeleteSnapshot" aria-hidden="true"></div>
                <div class="modal-card">
                    <header class="modal-card-head">
                        <p class="modal-card-title" id="snapshot-delete-title">{{ $t('settings.snapshots.delete_dialog_title') }}</p>
                        <button class="delete" @click="cancelDeleteSnapshot" :aria-label="$t('label.close')"></button>
                    </header>
                    <section class="modal-card-body">
                        <p>{{ $t('settings.snapshots.delete_dialog_body', { label: deleteTarget?.label || $t('settings.snapshots.unlabeled') }) }}</p>
                    </section>
                    <footer class="modal-card-foot">
                        <button
                            class="button is-danger"
                            :class="{ 'is-loading': isDeletingSnapshot }"
                            :disabled="isDeletingSnapshot"
                            @click="confirmDeleteSnapshot"
                            data-testid="snapshot-delete-confirm"
                        >
                            {{ $t('label.delete') }}
                        </button>
                        <button class="button" @click="cancelDeleteSnapshot" data-testid="snapshot-delete-cancel">{{ $t('label.cancel') }}</button>
                    </footer>
                </div>
            </div>

            <!-- Restore wizard: step 1 mode → step 2 diff (server token) →
                 step 3 confirm (typed RESTORE for replace) → step 4 result -->
            <div class="modal" :class="{ 'is-active': showRestoreWizard }" role="dialog" aria-modal="true" aria-labelledby="restore-wizard-title" data-testid="restore-wizard">
                <div class="modal-background" @click="cancelRestoreWizard" aria-hidden="true"></div>
                <div class="modal-card">
                    <header class="modal-card-head">
                        <p class="modal-card-title" id="restore-wizard-title">{{ $t('settings.snapshots.wizard_title') }}</p>
                        <button class="delete" @click="cancelRestoreWizard" :aria-label="$t('label.close')"></button>
                    </header>

                    <section class="modal-card-body">
                        <!-- Step 1: mode -->
                        <div v-if="wizardStep === 1">
                            <p class="mb-3">{{ $t('settings.snapshots.step_mode_title', { label: wizardSnapshot?.label || $t('settings.snapshots.unlabeled') }) }}</p>
                            <div class="field">
                                <label class="radio is-block mb-2">
                                    <input type="radio" v-model="wizardMode" value="merge" data-testid="wizard-mode-merge" />
                                    <strong>{{ $t('settings.snapshots.mode_merge') }}</strong>
                                    <p class="is-size-7 has-text-grey">{{ $t('settings.snapshots.mode_merge_help') }}</p>
                                </label>
                                <label class="radio is-block">
                                    <input type="radio" v-model="wizardMode" value="replace" data-testid="wizard-mode-replace" />
                                    <strong>{{ $t('settings.snapshots.mode_replace') }}</strong>
                                    <p class="is-size-7 has-text-grey">{{ $t('settings.snapshots.mode_replace_help') }}</p>
                                </label>
                            </div>
                        </div>

                        <!-- Step 2: dry-run diff -->
                        <div v-else-if="wizardStep === 2" data-testid="wizard-diff">
                            <div v-if="isDryRunning" class="has-text-centered py-4">
                                <span class="loader"></span>
                                <p class="mt-2">{{ $t('settings.snapshots.diff_loading') }}</p>
                            </div>
                            <template v-else-if="wizardDiff">
                                <div v-if="wizardDiff.key_mismatch_warning" class="notification is-warning is-light py-2">
                                    {{ $t('settings.snapshots.key_mismatch_banner') }}
                                </div>
                                <div v-if="wizardError" class="notification is-danger is-light py-2" data-testid="wizard-error">
                                    {{ wizardError }}
                                </div>
                                <div v-if="wizardDiff.warnings.length" class="notification is-warning is-light py-2">
                                    <p v-for="(warning, i) in wizardDiff.warnings" :key="i" class="is-size-7">{{ warning }}</p>
                                </div>

                                <p class="is-size-7 has-text-grey mb-2">{{ $t('settings.snapshots.diff_unchanged', { count: wizardDiff.unchanged_count }) }}</p>

                                <template v-if="wizardDiff.to_create.length">
                                    <p class="has-text-weight-semibold">{{ $t('settings.snapshots.diff_created', { count: wizardDiff.to_create.length }) }}</p>
                                    <ul class="is-size-7 mb-2" data-testid="diff-create-list">
                                        <li v-for="(row, i) in wizardDiff.to_create" :key="`c${i}`">{{ row.service }} / {{ row.account }}</li>
                                    </ul>
                                </template>
                                <template v-if="wizardDiff.to_update.length">
                                    <p class="has-text-weight-semibold">{{ $t('settings.snapshots.diff_updated', { count: wizardDiff.to_update.length }) }}</p>
                                    <ul class="is-size-7 mb-2" data-testid="diff-update-list">
                                        <li v-for="(row, i) in wizardDiff.to_update" :key="`u${i}`">{{ row.service }} / {{ row.account }}</li>
                                    </ul>
                                </template>
                                <template v-if="wizardDiff.to_delete.length">
                                    <p class="has-text-weight-semibold has-text-danger">{{ $t('settings.snapshots.diff_deleted', { count: wizardDiff.to_delete.length }) }}</p>
                                    <ul class="is-size-7 mb-2" data-testid="diff-delete-list">
                                        <li v-for="(row, i) in wizardDiff.to_delete" :key="`d${i}`">{{ row.service }} / {{ row.account }}</li>
                                    </ul>
                                </template>
                                <p
                                    v-if="!wizardDiff.to_create.length && !wizardDiff.to_update.length && !wizardDiff.to_delete.length"
                                    class="has-text-grey"
                                >
                                    {{ $t('settings.snapshots.diff_no_changes') }}
                                </p>
                            </template>
                        </div>

                        <!-- Step 3: confirm -->
                        <div v-else-if="wizardStep === 3">
                            <div v-if="wizardMode === 'replace'" class="notification is-danger is-light">
                                {{ $t('settings.snapshots.confirm_replace_help') }}
                            </div>
                            <div v-else>
                                <p class="mb-3">{{ $t('settings.snapshots.confirm_merge_help') }}</p>
                            </div>
                            <div v-if="wizardMode === 'replace'" class="field">
                                <label class="label is-size-7">{{ $t('settings.snapshots.confirm_placeholder') }}</label>
                                <div class="control">
                                    <input
                                        class="input"
                                        type="text"
                                        v-model="wizardConfirmText"
                                        placeholder="RESTORE"
                                        data-testid="wizard-confirm-input"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Step 4: result -->
                        <div v-else-if="wizardStep === 4" data-testid="wizard-result">
                            <div class="notification is-success is-light">
                                {{ $t('settings.snapshots.result_title') }}
                            </div>
                            <ul class="is-size-7" v-if="wizardResult">
                                <li>{{ $t('settings.snapshots.diff_created', { count: wizardResult.created_count }) }}</li>
                                <li>{{ $t('settings.snapshots.diff_updated', { count: wizardResult.updated_count }) }}</li>
                                <li>{{ $t('settings.snapshots.diff_deleted', { count: wizardResult.deleted_count }) }}</li>
                                <li v-if="wizardResult.groups_created">{{ $t('settings.snapshots.result_groups_created', { count: wizardResult.groups_created }) }}</li>
                            </ul>
                        </div>
                    </section>

                    <footer class="modal-card-foot">
                        <!-- Step 1 -->
                        <template v-if="wizardStep === 1">
                            <button class="button is-primary" :disabled="isDryRunning" @click="runWizardDiff" data-testid="wizard-next">
                                {{ $t('settings.snapshots.next_button') }}
                            </button>
                            <button class="button" @click="cancelRestoreWizard">{{ $t('label.cancel') }}</button>
                        </template>
                        <!-- Step 2 -->
                        <template v-else-if="wizardStep === 2">
                            <button class="button is-primary" :disabled="isDryRunning || !wizardDiff" @click="proceedToConfirm" data-testid="wizard-continue">
                                {{ $t('label.continue') }}
                            </button>
                            <button class="button" :disabled="isDryRunning" @click="wizardStep = 1">{{ $t('settings.snapshots.back_button') }}</button>
                        </template>
                        <!-- Step 3 -->
                        <template v-else-if="wizardStep === 3">
                            <button
                                class="button"
                                :class="wizardMode === 'replace' ? 'is-danger' : 'is-primary'"
                                :disabled="isRestoring || !canConfirmRestore"
                                @click="confirmRestore"
                                data-testid="wizard-restore-btn"
                            >
                                <span class="icon" v-if="isRestoring">
                                    <i class="fas fa-spinner fa-pulse"></i>
                                </span>
                                <span>{{ $t('settings.snapshots.restore_now') }}</span>
                            </button>
                            <button class="button" :disabled="isRestoring" @click="backToDiff">{{ $t('settings.snapshots.back_button') }}</button>
                        </template>
                        <!-- Step 4 -->
                        <template v-else>
                            <button class="button is-primary" @click="cancelRestoreWizard" data-testid="wizard-close">{{ $t('label.close') }}</button>
                        </template>
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
