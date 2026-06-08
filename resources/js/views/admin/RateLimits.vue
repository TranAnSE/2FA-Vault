<script setup>
    import tabs from './tabs'
    import { useNotify, TabBar } from '@2fauth/ui'
    import rateLimitService from '@/services/rateLimitService'

    const { t } = useI18n()
    const router = useRouter()
    const notify = useNotify()

    const period     = ref('24h')
    const data       = ref(null)
    const isLoading  = ref(false)

    const PERIODS = ['24h', '7d', '30d']

    onMounted(load)
    watch(period, load)

    async function load() {
        isLoading.value = true
        try {
            const { data: d } = await rateLimitService.getDashboard(period.value)
            data.value = d
        } catch {
            notify.alert({ text: t('error.data_cannot_be_refreshed_from_server') })
        } finally {
            isLoading.value = false
        }
    }

    const limitRatio = computed(() => {
        if (!data.value?.total) return 0
        return Math.round((data.value.limited / data.value.total) * 100)
    })
</script>

<template>
    <StackLayout>
        <template #header>
            <TabBar :tabs="tabs" :active-tab="'admin.rateLimits'" :is-responsive="false" @tab-selected="(to) => router.push({ name: to })" />
        </template>
        <template #content>
            <div class="level mb-4 mt-2">
                <div class="level-left">
                    <h2 class="title is-4 mb-0">{{ $t('title.rate_limit_dashboard') }}</h2>
                </div>
                <div class="level-right">
                    <div class="buttons are-small mb-0">
                        <button
                            v-for="p in PERIODS"
                            :key="p"
                            class="button"
                            :class="period === p ? 'is-info' : 'is-light'"
                            @click="period = p"
                        >{{ p }}</button>
                        <button class="button is-light" :class="{ 'is-loading': isLoading }" @click="load">
                            {{ $t('label.refresh') }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="data">
                <!-- Summary cards -->
                <div class="columns mb-4">
                    <div class="column">
                        <div class="box has-text-centered">
                            <p class="is-size-1 has-text-weight-bold">{{ data.total?.toLocaleString() ?? 0 }}</p>
                            <p class="is-size-7 has-text-grey">{{ $t('label.total_requests') }}</p>
                        </div>
                    </div>
                    <div class="column">
                        <div class="box has-text-centered">
                            <p class="is-size-1 has-text-weight-bold has-text-danger">{{ data.limited?.toLocaleString() ?? 0 }}</p>
                            <p class="is-size-7 has-text-grey">{{ $t('label.rate_limited') }}</p>
                        </div>
                    </div>
                    <div class="column">
                        <div class="box has-text-centered">
                            <p class="is-size-1 has-text-weight-bold" :class="limitRatio > 5 ? 'has-text-warning' : 'has-text-success'">
                                {{ limitRatio }}%
                            </p>
                            <p class="is-size-7 has-text-grey">{{ $t('label.limit_ratio') }}</p>
                        </div>
                    </div>
                </div>

                <!-- Top consumers -->
                <div class="columns">
                    <div class="column">
                        <div class="box">
                            <h5 class="title is-5">{{ $t('label.top_consumers') }}</h5>
                            <table class="table is-fullwidth is-narrow is-size-7">
                                <thead>
                                    <tr>
                                        <th>IP</th>
                                        <th>{{ $t('label.user') }}</th>
                                        <th>{{ $t('label.hits') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in data.topConsumers" :key="row.ip_address">
                                        <td>{{ row.ip_address }}</td>
                                        <td>{{ row.user_id ?? '—' }}</td>
                                        <td class="has-text-danger">{{ row.hit_count }}</td>
                                    </tr>
                                    <tr v-if="!data.topConsumers?.length">
                                        <td colspan="3" class="has-text-grey">{{ $t('message.no_data_available') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="column">
                        <div class="box">
                            <h5 class="title is-5">{{ $t('label.top_endpoints') }}</h5>
                            <table class="table is-fullwidth is-narrow is-size-7">
                                <thead>
                                    <tr>
                                        <th>{{ $t('label.endpoint') }}</th>
                                        <th>{{ $t('label.hits') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in data.topEndpoints" :key="row.endpoint">
                                        <td>{{ row.endpoint }}</td>
                                        <td class="has-text-danger">{{ row.hit_count }}</td>
                                    </tr>
                                    <tr v-if="!data.topEndpoints?.length">
                                        <td colspan="2" class="has-text-grey">{{ $t('message.no_data_available') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else-if="isLoading" class="has-text-centered py-6">
                <span class="loader"></span>
            </div>
        </template>
        <template #footer>
            <VueFooter>
                <NavigationButton action="close" @closed="router.push({ name: 'accounts' })" :current-page-title="$t('title.rate_limit_dashboard')" />
            </VueFooter>
        </template>
    </StackLayout>
</template>
