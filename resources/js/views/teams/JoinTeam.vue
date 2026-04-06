<template>
    <div class="join-team-container">
        <div class="join-team-card">
            <div v-if="isLoading" class="has-text-centered py-6">
                <span class="loader"></span>
            </div>
            <div v-else-if="result === 'success'" class="has-text-centered">
                <span class="icon is-large has-text-success">
                    <i class="fa fa-check-circle fa-3x"></i>
                </span>
                <h1 class="title mt-5">{{ $t('teams.join_team_success') }}</h1>
                <router-link to="/teams" class="button is-link mt-4">
                    {{ $t('teams.back_to_teams') }}
                </router-link>
            </div>
            <div v-else-if="result === 'error'" class="has-text-centered">
                <span class="icon is-large has-text-danger">
                    <i class="fa fa-exclamation-circle fa-3x"></i>
                </span>
                <h1 class="title mt-5">{{ $t('teams.join_team_error') }}</h1>
                <p class="mt-3">{{ errorMessage }}</p>
                <router-link to="/teams" class="button is-link mt-4">
                    {{ $t('teams.back_to_teams') }}
                </router-link>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTeamsStore } from '@/stores/teams'

const route = useRoute()
const router = useRouter()
const teamsStore = useTeamsStore()

const isLoading = ref(true)
const result = ref(null)
const errorMessage = ref('')

onMounted(async () => {
    const token = route.params.token
    if (!token) {
        result.value = 'error'
        errorMessage.value = 'Invalid invitation link.'
        isLoading.value = false
        return
    }

    try {
        await teamsStore.acceptEmailInvitation(token)
        result.value = 'success'
    } catch (error) {
        result.value = 'error'
        errorMessage.value = error.response?.data?.message || 'Failed to join team.'
    } finally {
        isLoading.value = false
    }
})
</script>

<style scoped>
.join-team-container {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 60vh;
    padding: 2rem;
}

.join-team-card {
    max-width: 500px;
    width: 100%;
}
</style>
