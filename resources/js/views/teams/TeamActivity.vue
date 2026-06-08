<script setup>
    import ActivityTimeline from '@/components/ActivityTimeline.vue'
    import teamActivityService from '@/services/teamActivityService'
    import { useNotify } from '@2fauth/ui'

    const { t } = useI18n()
    const route  = useRoute()
    const router = useRouter()
    const notify = useNotify()

    const teamId     = route.params.id
    const activities = ref([])
    const pagination = ref(null)
    const isLoading  = ref(false)
    const actionFilter = ref('')

    onMounted(load)

    async function load(page = 1) {
        isLoading.value = true
        try {
            const params = { page }
            if (actionFilter.value) params.actions = actionFilter.value
            const { data } = await teamActivityService.getActivity(teamId, params)
            activities.value = data.data
            pagination.value  = data
        } catch {
            notify.alert({ text: t('error.data_cannot_be_refreshed_from_server') })
        } finally {
            isLoading.value = false
        }
    }

    async function exportLog() {
        try {
            const { data } = await teamActivityService.exportActivity(teamId)
            const url  = URL.createObjectURL(new Blob([data], { type: 'application/json' }))
            const link = document.createElement('a')
            link.href = url
            link.download = `team-activity-${teamId}.json`
            link.click()
            URL.revokeObjectURL(url)
        } catch {
            notify.alert({ text: t('error.download_failed') })
        }
    }
</script>

<template>
    <div class="container py-5">
        <div class="level mb-4">
            <div class="level-left">
                <div>
                    <router-link :to="{ name: 'teamDetail', params: { id: teamId } }" class="is-size-7 has-text-grey">
                        ← {{ $t('label.back_to_team') }}
                    </router-link>
                    <h2 class="title is-3 mt-1">{{ $t('title.team_activity') }}</h2>
                </div>
            </div>
            <div class="level-right">
                <button class="button is-light is-small" @click="exportLog">{{ $t('label.export_log') }}</button>
            </div>
        </div>

        <div class="box">
            <ActivityTimeline v-if="!isLoading" :activities="activities" />
            <div v-else class="has-text-centered py-6">
                <span class="loader"></span>
            </div>

            <!-- Pagination -->
            <nav v-if="pagination && pagination.last_page > 1" class="pagination is-centered mt-4" role="navigation">
                <button
                    v-for="page in pagination.last_page"
                    :key="page"
                    class="pagination-link"
                    :class="{ 'is-current': page === pagination.current_page }"
                    @click="load(page)"
                >{{ page }}</button>
            </nav>
        </div>
    </div>
</template>
