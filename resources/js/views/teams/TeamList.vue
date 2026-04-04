<template>
  <div class="teams-list">
    <div class="header">
      <h1 class="title">{{ $t('teams.my_teams') }}</h1>
      <router-link to="/teams/create" class="button is-link">
        <span class="icon">
          <i class="fa fa-plus"></i>
        </span>
        <span>{{ $t('teams.create_team') }}</span>
      </router-link>
    </div>

    <div v-if="isLoading" class="has-text-centered">
      <span class="icon is-large">
        <i class="fa fa-spinner fa-pulse"></i>
      </span>
    </div>

    <div v-else-if="teams.length === 0" class="notification is-info">
      <p>{{ $t('teams.no_teams_yet') }}</p>
      <p>{{ $t('teams.create_first_team') }}</p>
    </div>

    <div v-else class="teams-grid">
      <div 
        v-for="team in teams" 
        :key="team.id" 
        class="team-card box"
        @click="goToTeam(team.id)"
      >
        <div class="team-card-header">
          <h3 class="team-name">{{ team.name }}</h3>
          <span class="tag" :class="getRoleClass(team.role)">
            {{ team.role }}
          </span>
        </div>
        
        <div class="team-card-body">
          <p class="team-info">
            <span class="icon">
              <i class="fa fa-users"></i>
            </span>
            {{ team.members_count }} {{ $t('teams.members') }}
          </p>
          <p class="team-info">
            <span class="icon">
              <i class="fa fa-user"></i>
            </span>
            {{ $t('teams.owner') }}: {{ team.owner_name }}
          </p>
          <p class="team-info text-muted">
            {{ $t('teams.created') }}: {{ formatDate(team.created_at) }}
          </p>
        </div>

        <div v-if="team.invite_code" class="team-card-footer">
          <button 
            @click.stop="copyInviteCode(team.invite_code)"
            class="button is-small is-light"
          >
            <span class="icon">
              <i class="fa fa-copy"></i>
            </span>
            <span>{{ $t('teams.copy_invite_code') }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useTeamsStore } from '@/stores/teams'
import { useNotifyStore } from '@/stores/notify'

const router = useRouter()
const teamsStore = useTeamsStore()
const notifyStore = useNotifyStore()

const teams = ref([])
const isLoading = ref(true)

onMounted(async () => {
  await loadTeams()
})

async function loadTeams() {
  isLoading.value = true
  try {
    teams.value = await teamsStore.fetchTeams()
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to load teams')
  } finally {
    isLoading.value = false
  }
}

function goToTeam(teamId) {
  router.push(`/teams/${teamId}`)
}

function getRoleClass(role) {
  const roleClasses = {
    owner: 'is-danger',
    admin: 'is-warning',
    member: 'is-info',
    viewer: 'is-light',
  }
  return roleClasses[role] || 'is-light'
}

function formatDate(dateString) {
  return new Date(dateString).toLocaleDateString()
}

function copyInviteCode(code) {
  navigator.clipboard.writeText(code)
  notifyStore.success('Invite code copied to clipboard')
}
</script>

<style scoped>
.teams-list {
  padding: 2rem;
  max-width: 1200px;
  margin: 0 auto;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 2rem;
}

.teams-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 1.5rem;
}

.team-card {
  cursor: pointer;
  transition: transform 0.2s, box-shadow 0.2s;
}

.team-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
}

.team-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}

.team-name {
  font-size: 1.25rem;
  font-weight: 600;
  margin: 0;
}

.team-card-body {
  margin-bottom: 1rem;
}

.team-info {
  margin-bottom: 0.5rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.text-muted {
  color: #7a7a7a;
  font-size: 0.875rem;
}

.team-card-footer {
  padding-top: 1rem;
  border-top: 1px solid #dbdbdb;
}
</style>
