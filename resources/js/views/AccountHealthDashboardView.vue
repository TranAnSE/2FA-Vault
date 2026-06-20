<script setup>
/**
 * Vault health dashboard: aggregates per-account scores and lists weak accounts
 * (grade C or below). "Show weak only" filter narrows the table.
 */
import { ref, computed, onMounted } from 'vue'
import { useAccountHealth } from '@/composables/useAccountHealth'
import { useTwofaccounts } from '@/stores/twofaccounts'
import AccountHealthBadge from '@/components/AccountHealthBadge.vue'

const { summary, fetchSummary, get } = useAccountHealth()
const twofaccounts = useTwofaccounts()

const showWeakOnly = ref(false)
const loading = ref(true)

const accounts = computed(() => twofaccounts.items || [])

const rows = computed(() =>
    accounts.value
        .map(a => ({ account: a, health: get(a.id) }))
        .filter(row => (showWeakOnly.value ? isWeak(row.health) : true))
)

function isWeak(health) {
    if (!health) return false
    return ['C', 'D', 'F'].includes(health.combined_grade || health.grade)
}

onMounted(async () => {
    try {
        await twofaccounts.fetchAll?.()
    } catch (_) { /* store may already be populated */ }
    await fetchSummary().catch(() => {})
    loading.value = false
})
</script>

<template>
    <section class="container health-dashboard">
        <h1 class="title is-3">{{ $t('heading.vault_health') }}</h1>

        <div v-if="summary" class="health-dashboard__summary">
            <div class="level">
                <div class="level-item has-text-centered">
                    <div>
                        <p class="heading">{{ $t('label.total_accounts') }}</p>
                        <p class="title">{{ summary.total }}</p>
                    </div>
                </div>
                <div class="level-item has-text-centered">
                    <div>
                        <p class="heading">{{ $t('label.average_score') }}</p>
                        <p class="title">{{ summary.average_server_total }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" v-model="showWeakOnly" />
                {{ $t('label.show_weak_only') }}
            </label>
        </div>

        <table class="table is-fullwidth is-striped">
            <thead>
                <tr>
                    <th>{{ $t('field.service') }}</th>
                    <th>{{ $t('field.account') }}</th>
                    <th>{{ $t('label.grade') }}</th>
                    <th>{{ $t('label.score') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.account.id">
                    <td>{{ row.account.service }}</td>
                    <td>{{ row.account.account }}</td>
                    <td>
                        <AccountHealthBadge
                            :grade="row.health?.combined_grade || row.health?.grade"
                            :total="row.health?.combined_total || row.health?.server_total"
                            :mode="row.health?.mode" />
                    </td>
                    <td>{{ row.health?.combined_total ?? row.health?.server_total ?? '—' }}</td>
                </tr>
                <tr v-if="!rows.length">
                    <td colspan="4" class="has-text-grey">{{ $t('message.no_accounts') }}</td>
                </tr>
            </tbody>
        </table>
    </section>
</template>

<style scoped>
.health-dashboard__summary {
    margin-bottom: 1.5rem;
}
</style>
