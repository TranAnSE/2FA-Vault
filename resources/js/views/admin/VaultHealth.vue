<script setup>
    import tabs from './tabs'
    import { useNotify, TabBar } from '@2fauth/ui'
    import { useTwofaccounts } from '@/stores/twofaccounts'
    import twofaccountService from '@/services/twofaccountService'
    import { calculateHealthReport, exportHealthReport } from '@/services/vaultHealth'
    import VaultHealthGauge from '@/components/VaultHealthGauge.vue'
    import VaultHealthDuplicates from '@/components/VaultHealthDuplicates.vue'
    import VaultHealthUnused from '@/components/VaultHealthUnused.vue'
    import VaultHealthSecretStrength from '@/components/VaultHealthSecretStrength.vue'

    const { t } = useI18n()
    const notify = useNotify()
    const twofaccounts = useTwofaccounts()

    const isLoading = ref(false)
    const report = ref(null)
    const accounts = ref([])

    onMounted(async () => {
        await runAnalysis()
    })

    async function runAnalysis() {
        isLoading.value = true
        try {
            // Fetch accounts with secrets for health analysis
            const response = await twofaccountService.getAll(false)
            accounts.value = Array.isArray(response?.data) ? response.data : []

            // Use already-decrypted accounts from store if vault is unlocked
            // (store items have decrypted secrets when E2EE vault is open)
            const storeItems = twofaccounts.items
            if (storeItems.length && storeItems.some(a => a.secret)) {
                accounts.value = storeItems
            }

            report.value = calculateHealthReport(accounts.value)
        } catch {
            notify.alert({ text: t('error.data_cannot_be_refreshed_from_server') })
        } finally {
            isLoading.value = false
        }
    }

    function doExport() {
        if (report.value) {
            exportHealthReport(report.value, accounts.value)
        }
    }

    const scoreColor = computed(() => {
        if (!report.value) return 'is-grey'
        const s = report.value.overall
        if (s >= 76) return 'is-success'
        if (s >= 51) return 'is-warning'
        if (s >= 26) return 'is-danger'
        return 'is-danger'
    })
</script>

<template>
    <div>
        <TabBar :tabs="tabs" :active-tab="'admin.health'" :is-responsive="false" @tab-selected="(to) => $router.push({ name: to })" />

        <div class="container py-5">
            <!-- Header -->
            <div class="level mb-5">
                <div class="level-left">
                    <div>
                        <h2 class="title is-3">{{ $t('title.vault_health') }}</h2>
                        <p class="subtitle is-6 has-text-grey">{{ $t('message.vault_health_subtitle') }}</p>
                    </div>
                </div>
                <div class="level-right">
                    <div class="buttons">
                        <button class="button is-light" :class="{ 'is-loading': isLoading }" @click="runAnalysis">
                            {{ $t('label.refresh') }}
                        </button>
                        <button v-if="report" class="button is-info is-light" @click="doExport">
                            {{ $t('label.export_report') }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="isLoading" class="has-text-centered py-6">
                <span class="icon is-large has-text-grey"><i class="mdi mdi-loading mdi-spin mdi-36px"></i></span>
            </div>

            <template v-else-if="report">
                <!-- Overall Score -->
                <div class="columns is-vcentered mb-5">
                    <div class="column is-narrow has-text-centered">
                        <VaultHealthGauge :score="report.overall" :size="160" />
                        <p class="mt-2 is-size-6 has-text-grey">{{ $t('label.overall_health') }}</p>
                    </div>
                    <div class="column">
                        <div class="columns is-multiline">
                            <div class="column is-half">
                                <div class="box has-text-centered p-3">
                                    <p class="is-size-1 has-text-weight-bold" :class="report.duplicates.length ? 'has-text-warning' : 'has-text-success'">
                                        {{ report.duplicates.length }}
                                    </p>
                                    <p class="is-size-7 has-text-grey">{{ $t('label.duplicate_groups') }}</p>
                                </div>
                            </div>
                            <div class="column is-half">
                                <div class="box has-text-centered p-3">
                                    <p class="is-size-1 has-text-weight-bold" :class="report.unused.length ? 'has-text-warning' : 'has-text-success'">
                                        {{ report.unused.length }}
                                    </p>
                                    <p class="is-size-7 has-text-grey">{{ $t('label.unused_accounts') }}</p>
                                </div>
                            </div>
                            <div class="column is-half">
                                <div class="box has-text-centered p-3">
                                    <p class="is-size-1 has-text-weight-bold" :class="report.weakSecrets.length ? 'has-text-danger' : 'has-text-success'">
                                        {{ report.weakSecrets.length }}
                                    </p>
                                    <p class="is-size-7 has-text-grey">{{ $t('label.weak_secrets') }}</p>
                                </div>
                            </div>
                            <div class="column is-half">
                                <div class="box has-text-centered p-3">
                                    <p class="is-size-1 has-text-weight-bold" :class="report.incomplete.length ? 'has-text-warning' : 'has-text-success'">
                                        {{ report.incomplete.length }}
                                    </p>
                                    <p class="is-size-7 has-text-grey">{{ $t('label.incomplete_accounts') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detail Sections -->
                <div class="box mb-4">
                    <VaultHealthDuplicates :duplicate-groups="report.duplicates" />
                </div>
                <div class="box mb-4">
                    <VaultHealthUnused :unused="report.unused" />
                </div>
                <div class="box mb-4">
                    <VaultHealthSecretStrength :weak-secrets="report.weakSecrets" :total-accounts="report.totalAccounts" />
                </div>
            </template>

            <div v-else class="has-text-centered py-6">
                <p class="has-text-grey">{{ $t('message.no_data_available') }}</p>
            </div>
        </div>
    </div>
</template>
