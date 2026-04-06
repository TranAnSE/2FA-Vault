<template>
  <div class="team-detail">
    <div v-if="isLoading" class="has-text-centered">
      <span class="icon is-large">
        <i class="fa fa-spinner fa-pulse"></i>
      </span>
    </div>

    <div v-else-if="team" class="container">
      <div class="header">
        <div>
          <router-link to="/teams" class="back-link">
            <span class="icon">
              <i class="fa fa-arrow-left"></i>
            </span>
            {{ $t('teams.back_to_teams') }}
          </router-link>
          <h1 class="title">{{ team.name }}</h1>
          <span class="tag" :class="getRoleClass(userRole)">
            {{ userRole }}
          </span>
        </div>

        <div class="actions">
          <button
            v-if="canInvite"
            @click="showInviteModal = true"
            class="button is-link"
          >
            <span class="icon"><i class="fa fa-user-plus"></i></span>
            <span>{{ $t('teams.invite_member') }}</span>
          </button>

          <button
            v-if="canUpdate"
            @click="showEditModal = true"
            class="button is-info"
          >
            <span class="icon"><i class="fa fa-edit"></i></span>
            <span>{{ $t('teams.edit_team') }}</span>
          </button>

          <button
            v-if="!isOwner"
            @click="leaveTeam"
            class="button is-warning"
          >
            <span class="icon"><i class="fa fa-sign-out-alt"></i></span>
            <span>{{ $t('teams.leave_team') }}</span>
          </button>

          <button
            v-if="canDelete"
            @click="deleteTeam"
            class="button is-danger"
          >
            <span class="icon"><i class="fa fa-trash"></i></span>
            <span>{{ $t('teams.delete_team') }}</span>
          </button>
        </div>
      </div>

      <!-- Members Section -->
      <div class="box members-section">
        <h2 class="subtitle">{{ $t('teams.members') }} ({{ team.members.length }})</h2>

        <table class="table is-fullwidth is-hoverable">
          <thead>
            <tr>
              <th>{{ $t('teams.name') }}</th>
              <th>{{ $t('teams.email') }}</th>
              <th>{{ $t('teams.role') }}</th>
              <th>{{ $t('teams.joined_at') }}</th>
              <th v-if="canManageMembers">{{ $t('teams.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in team.members" :key="member.id">
              <td>{{ member.name }}</td>
              <td>{{ member.email }}</td>
              <td>
                <span class="tag" :class="getRoleClass(member.role)">
                  {{ member.role }}
                </span>
              </td>
              <td>{{ formatDate(member.joined_at) }}</td>
              <td v-if="canManageMembers">
                <div class="buttons">
                  <button
                    v-if="canChangeRole(member)"
                    @click="changeRole(member)"
                    class="button is-small is-info"
                  >
                    <span class="icon"><i class="fa fa-user-cog"></i></span>
                  </button>
                  <button
                    v-if="canRemoveMember(member)"
                    @click="removeMember(member)"
                    class="button is-small is-danger"
                  >
                    <span class="icon"><i class="fa fa-user-times"></i></span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pending Invitations Section -->
      <div class="box invitations-section" v-if="canInvite && pendingInvitations.length > 0">
        <h2 class="subtitle">{{ $t('teams.pending_invitations') }} ({{ pendingInvitations.length }})</h2>
        <table class="table is-fullwidth is-hoverable">
          <thead>
            <tr>
              <th>{{ $t('teams.email') }}</th>
              <th>{{ $t('teams.role') }}</th>
              <th>{{ $t('teams.expires_at') }}</th>
              <th>{{ $t('teams.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="inv in pendingInvitations" :key="inv.id">
              <td>{{ inv.email }}</td>
              <td><span class="tag" :class="getRoleClass(inv.role)">{{ inv.role }}</span></td>
              <td>{{ formatDate(inv.expires_at) }}</td>
              <td>
                <button @click="cancelInvitation(inv)" class="button is-small is-warning">
                  <span class="icon"><i class="fa fa-times"></i></span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Shared Accounts Section -->
      <div class="box shared-accounts-section" v-if="canInvite">
        <h2 class="subtitle">{{ $t('teams.shared_accounts') }} ({{ sharedAccounts.length }})</h2>
        <table v-if="sharedAccounts.length > 0" class="table is-fullwidth is-hoverable">
          <thead>
            <tr>
              <th>{{ $t('teams.service') }}</th>
              <th>{{ $t('teams.account') }}</th>
              <th>{{ $t('teams.shared_by') }}</th>
              <th>{{ $t('teams.access_level') }}</th>
              <th>{{ $t('teams.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="sa in sharedAccounts" :key="sa.id">
              <td>{{ sa.account_service }}</td>
              <td>{{ sa.account_name }}</td>
              <td>{{ sa.shared_by }}</td>
              <td><span class="tag is-info">{{ sa.access_level }}</span></td>
              <td>
                <button @click="handleUnshareAccount(sa)" class="button is-small is-danger">
                  <span class="icon"><i class="fa fa-times"></i></span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="has-text-grey">{{ $t('teams.no_shared_accounts') }}</p>
      </div>

      <!-- Invite Modal -->
      <div class="modal" :class="{ 'is-active': showInviteModal }">
        <div class="modal-background" @click="showInviteModal = false"></div>
        <div class="modal-card">
          <header class="modal-card-head">
            <p class="modal-card-title">{{ $t('teams.invite_member') }}</p>
            <button class="delete" @click="showInviteModal = false"></button>
          </header>
          <section class="modal-card-body">
            <!-- Invite Code -->
            <div class="field">
              <label class="label">{{ $t('teams.invite_code') }}</label>
              <div class="control has-icons-right">
                <input
                  class="input"
                  type="text"
                  :value="inviteCode"
                  readonly
                >
                <span class="icon is-right" style="pointer-events: all; cursor: pointer;" @click="copyInviteCode">
                  <i class="fa fa-copy"></i>
                </span>
              </div>
            </div>
            <button @click="generateNewInviteCode" class="button is-link">
              {{ $t('teams.generate_new_code') }}
            </button>

            <hr />

            <!-- Email Invitation -->
            <p class="has-text-weight-semibold mb-3">{{ $t('teams.invite_by_email') }}</p>
            <div class="field">
              <label class="label">{{ $t('teams.email') }}</label>
              <div class="control">
                <input
                  class="input"
                  type="email"
                  v-model="inviteEmail"
                  :placeholder="$t('teams.email_placeholder')"
                />
              </div>
            </div>
            <div class="field">
              <label class="label">{{ $t('teams.role') }}</label>
              <div class="control">
                <div class="select">
                  <select v-model="inviteRole">
                    <option value="member">Member</option>
                    <option value="admin">Admin</option>
                    <option value="viewer">Viewer</option>
                  </select>
                </div>
              </div>
            </div>
            <button @click="sendEmailInvitation" class="button is-primary" :disabled="!inviteEmail || isSendingInvitation">
              <span class="icon" v-if="isSendingInvitation"><i class="fa fa-spinner fa-pulse"></i></span>
              <span>{{ $t('teams.send_invitation') }}</span>
            </button>
          </section>
        </div>
      </div>

      <!-- Edit Modal -->
      <div class="modal" :class="{ 'is-active': showEditModal }">
        <div class="modal-background" @click="showEditModal = false"></div>
        <div class="modal-card">
          <header class="modal-card-head">
            <p class="modal-card-title">{{ $t('teams.edit_team') }}</p>
            <button class="delete" @click="showEditModal = false"></button>
          </header>
          <section class="modal-card-body">
            <div class="field">
              <label class="label">{{ $t('teams.team_name') }}</label>
              <div class="control">
                <input
                  class="input"
                  type="text"
                  v-model="editTeamName"
                />
              </div>
            </div>
          </section>
          <footer class="modal-card-foot">
            <button @click="updateTeam" class="button is-success">{{ $t('teams.save') }}</button>
            <button @click="showEditModal = false" class="button">{{ $t('teams.cancel') }}</button>
          </footer>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTeamsStore } from '@/stores/teams'
import { useNotifyStore } from '@/stores/notify'
import { useUserStore } from '@/stores/user'

const route = useRoute()
const router = useRouter()
const teamsStore = useTeamsStore()
const notifyStore = useNotifyStore()
const userStore = useUserStore()

const team = ref(null)
const isLoading = ref(true)
const showInviteModal = ref(false)
const showEditModal = ref(false)
const editTeamName = ref('')
const inviteCode = ref('')

// Email invitation state
const inviteEmail = ref('')
const inviteRole = ref('member')
const isSendingInvitation = ref(false)

// Shared accounts + invitations
const sharedAccounts = ref([])
const pendingInvitations = ref([])

const userRole = computed(() => team.value?.role || 'viewer')
const isOwner = computed(() => userRole.value === 'owner')
const canUpdate = computed(() => ['owner', 'admin'].includes(userRole.value))
const canDelete = computed(() => isOwner.value)
const canInvite = computed(() => ['owner', 'admin'].includes(userRole.value))
const canManageMembers = computed(() => ['owner', 'admin'].includes(userRole.value))

onMounted(async () => {
  await loadTeam()
})

async function loadTeam() {
  isLoading.value = true
  try {
    team.value = await teamsStore.fetchTeamDetail(route.params.id)
    editTeamName.value = team.value.name
    inviteCode.value = team.value.invite_code || ''

    // Fetch shared accounts and pending invitations in parallel
    const [shared, invites] = await Promise.allSettled([
      teamsStore.fetchSharedAccounts(route.params.id),
      teamsStore.fetchInvitations(route.params.id),
    ])
    sharedAccounts.value = shared.status === 'fulfilled' ? shared.value : []
    pendingInvitations.value = invites.status === 'fulfilled' ? invites.value : []
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to load team')
    router.push('/teams')
  } finally {
    isLoading.value = false
  }
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

async function generateNewInviteCode() {
  try {
    const response = await teamsStore.generateInviteCode(team.value.id)
    inviteCode.value = response.invite_code
    notifyStore.success('New invite code generated')
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to generate invite code')
  }
}

function copyInviteCode() {
  navigator.clipboard.writeText(inviteCode.value)
  notifyStore.success('Invite code copied to clipboard')
}

async function updateTeam() {
  try {
    await teamsStore.updateTeam(team.value.id, { name: editTeamName.value })
    team.value.name = editTeamName.value
    showEditModal.value = false
    notifyStore.success('Team updated successfully')
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to update team')
  }
}

async function leaveTeam() {
  if (!confirm('Are you sure you want to leave this team?')) return
  try {
    await teamsStore.leaveTeam(team.value.id)
    notifyStore.success('Left team successfully')
    router.push('/teams')
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to leave team')
  }
}

async function deleteTeam() {
  if (!confirm('Are you sure you want to delete this team? This action cannot be undone.')) return
  try {
    await teamsStore.deleteTeam(team.value.id)
    notifyStore.success('Team deleted successfully')
    router.push('/teams')
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to delete team')
  }
}

function canChangeRole(member) {
  return isOwner.value && member.role !== 'owner'
}

function canRemoveMember(member) {
  return canManageMembers.value && member.role !== 'owner'
}

async function changeRole(member) {
  const newRole = prompt('Enter new role (admin, member, viewer):', member.role)
  if (!newRole || !['admin', 'member', 'viewer'].includes(newRole)) return
  try {
    await teamsStore.updateMemberRole(team.value.id, member.id, newRole)
    member.role = newRole
    notifyStore.success('Member role updated')
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to update role')
  }
}

async function removeMember(member) {
  if (!confirm(`Remove ${member.name} from the team?`)) return
  try {
    await teamsStore.removeMember(team.value.id, member.id)
    team.value.members = team.value.members.filter(m => m.id !== member.id)
    notifyStore.success('Member removed')
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to remove member')
  }
}

async function handleUnshareAccount(sa) {
  if (!confirm(`Unshare ${sa.account_service} (${sa.account_name}) from this team?`)) return
  try {
    await teamsStore.unshareAccount(team.value.id, sa.twofaccount_id)
    sharedAccounts.value = sharedAccounts.value.filter(s => s.id !== sa.id)
    notifyStore.success('Account unshared')
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to unshare account')
  }
}

async function sendEmailInvitation() {
  if (!inviteEmail.value) return
  isSendingInvitation.value = true
  try {
    await teamsStore.inviteByEmail(team.value.id, inviteEmail.value, inviteRole.value)
    const invites = await teamsStore.fetchInvitations(team.value.id)
    pendingInvitations.value = invites
    inviteEmail.value = ''
    notifyStore.success('Invitation sent successfully')
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to send invitation')
  } finally {
    isSendingInvitation.value = false
  }
}

async function cancelInvitation(inv) {
  if (!confirm(`Cancel invitation to ${inv.email}?`)) return
  try {
    await teamsStore.cancelInvitation(team.value.id, inv.id)
    pendingInvitations.value = pendingInvitations.value.filter(i => i.id !== inv.id)
    notifyStore.success('Invitation cancelled')
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to cancel invitation')
  }
}
</script>

<style scoped>
.team-detail {
  padding: 2rem;
  max-width: 1200px;
  margin: 0 auto;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 2rem;
}

.back-link {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.title {
  margin-bottom: 0.5rem;
}

.actions {
  display: flex;
  gap: 0.5rem;
}

.members-section,
.invitations-section,
.shared-accounts-section {
  margin-top: 2rem;
}

.subtitle {
  margin-bottom: 1rem;
}
</style>
