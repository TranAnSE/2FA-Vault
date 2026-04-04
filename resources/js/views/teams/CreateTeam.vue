<template>
  <div class="create-team">
    <div class="container">
      <div class="header">
        <router-link to="/teams" class="back-link">
          <span class="icon">
            <i class="fa fa-arrow-left"></i>
          </span>
          {{ $t('teams.back_to_teams') }}
        </router-link>
        <h1 class="title">{{ $t('teams.create_new_team') }}</h1>
      </div>

      <div class="box">
        <form @submit.prevent="createTeam">
          <div class="field">
            <label class="label">{{ $t('teams.team_name') }}</label>
            <div class="control">
              <input 
                class="input" 
                type="text" 
                v-model="teamName"
                :placeholder="$t('teams.enter_team_name')"
                required
                autofocus
              >
            </div>
            <p v-if="errors.name" class="help is-danger">{{ errors.name }}</p>
          </div>

          <div class="field is-grouped">
            <div class="control">
              <button 
                type="submit" 
                class="button is-link"
                :class="{ 'is-loading': isSubmitting }"
                :disabled="!teamName || isSubmitting"
              >
                <span class="icon">
                  <i class="fa fa-check"></i>
                </span>
                <span>{{ $t('teams.create_team') }}</span>
              </button>
            </div>
            <div class="control">
              <router-link to="/teams" class="button is-light">
                {{ $t('teams.cancel') }}
              </router-link>
            </div>
          </div>
        </form>
      </div>

      <div class="notification is-info is-light">
        <p><strong>{{ $t('teams.info_title') }}</strong></p>
        <ul>
          <li>{{ $t('teams.info_owner') }}</li>
          <li>{{ $t('teams.info_invite') }}</li>
          <li>{{ $t('teams.info_manage') }}</li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useTeamsStore } from '@/stores/teams'
import { useNotifyStore } from '@/stores/notify'

const router = useRouter()
const teamsStore = useTeamsStore()
const notifyStore = useNotifyStore()

const teamName = ref('')
const isSubmitting = ref(false)
const errors = ref({})

async function createTeam() {
  if (!teamName.value.trim()) {
    errors.value.name = 'Team name is required'
    return
  }

  isSubmitting.value = true
  errors.value = {}

  try {
    const team = await teamsStore.createTeam({ name: teamName.value })
    notifyStore.success('Team created successfully!')
    router.push(`/teams/${team.id}`)
  } catch (error) {
    if (error.response?.data?.errors) {
      errors.value = error.response.data.errors
    } else {
      notifyStore.error(error.response?.data?.message || 'Failed to create team')
    }
  } finally {
    isSubmitting.value = false
  }
}
</script>

<style scoped>
.create-team {
  padding: 2rem;
  max-width: 800px;
  margin: 0 auto;
}

.header {
  margin-bottom: 2rem;
}

.back-link {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.title {
  margin-bottom: 1rem;
}

.notification ul {
  margin-left: 1.5rem;
  margin-top: 0.5rem;
}

.notification li {
  margin-bottom: 0.25rem;
}
</style>
