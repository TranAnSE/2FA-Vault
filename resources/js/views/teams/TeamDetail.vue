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
        <div class="is-flex is-justify-content-space-between is-align-items-center mb-3">
          <h2 class="subtitle mb-0">{{ $t('teams.shared_accounts') }} ({{ sharedAccounts.length }})</h2>
          <button v-if="isOwner" class="button is-small is-info" @click="showShareModal = true">
            {{ $t('teams.share_encrypted') }}
          </button>
        </div>
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
      <div class="modal" :class="{ 'is-active': showInviteModal }" role="dialog" aria-modal="true" aria-labelledby="invite-modal-title" @keydown.escape="showInviteModal = false">
        <div class="modal-background" @click="showInviteModal = false" aria-hidden="true"></div>
        <div class="modal-card">
          <header class="modal-card-head">
            <p class="modal-card-title" id="invite-modal-title">{{ $t('teams.invite_member') }}</p>
            <button class="delete" :aria-label="$t('label.close')" @click="showInviteModal = false"></button>
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
                    <option value="member">{{ $t('teams.role_member') }}</option>
                    <option value="admin">{{ $t('teams.role_admin') }}</option>
                    <option value="viewer">{{ $t('teams.role_viewer') }}</option>
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

      <!-- Encrypted Share Modal -->
      <div class="modal" :class="{ 'is-active': showShareModal }" role="dialog" aria-modal="true" aria-labelledby="share-modal-title" @keydown.escape="showShareModal = false">
        <div class="modal-background" @click="showShareModal = false" aria-hidden="true"></div>
        <div class="modal-card">
          <header class="modal-card-head">
            <p class="modal-card-title" id="share-modal-title">{{ $t('teams.share_encrypted') }}</p>
            <button class="delete" :aria-label="$t('label.close')" @click="showShareModal = false"></button>
          </header>
          <section class="modal-card-body">
            <div v-if="!keyPairReady" class="notification is-warning is-size-7 mb-3">
              {{ $t('teams.key_pair_not_ready') }}
              <button class="button is-small is-warning ml-3" @click="initKeyPair" :class="{ 'is-loading': isInitingKeys }">
                {{ $t('teams.setup_key_pair') }}
              </button>
            </div>
            <div v-else>
              <div class="field">
                <label class="label">{{ $t('label.access_level') }}</label>
                <div class="select">
                  <select v-model="shareAccessLevel">
                    <option value="read">{{ $t('label.read_only') }}</option>
                    <option value="write">{{ $t('label.full_access') }}</option>
                  </select>
                </div>
              </div>
              <div class="field">
                <label class="label">{{ $t('teams.select_members') }}</label>
                <div v-for="member in nonOwnerMembers" :key="member.id" class="is-flex is-align-items-center mb-2">
                  <input type="checkbox" :id="'member-' + member.id" :value="member.id" v-model="selectedMemberIds" class="mr-2" />
                  <label :for="'member-' + member.id">{{ member.name }} ({{ member.email }})</label>
                </div>
              </div>
              <div class="field">
                <label class="label">{{ $t('label.account') }}</label>
                <div class="control">
                  <input class="input" type="text" :value="shareAccountSecret ? '••••••••' : $t('teams.enter_secret_to_share')" readonly />
                  <input class="input mt-1" type="text" v-model="shareAccountSecret" :placeholder="$t('teams.paste_totp_secret')" />
                </div>
                <p class="help">{{ $t('teams.secret_never_sent_to_server') }}</p>
              </div>
            </div>
          </section>
          <footer class="modal-card-foot">
            <button
              @click="handleShareEncrypted"
              class="button is-success"
              :class="{ 'is-loading': isSharing }"
              :disabled="!keyPairReady || !shareAccountSecret || selectedMemberIds.length === 0"
            >
              {{ $t('teams.share_now') }}
            </button>
            <button @click="showShareModal = false" class="button">{{ $t('label.cancel') }}</button>
          </footer>
        </div>
      </div>

      <!-- Edit Modal -->
      <div class="modal" :class="{ 'is-active': showEditModal }" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title" @keydown.escape="showEditModal = false">
        <div class="modal-background" @click="showEditModal = false" aria-hidden="true"></div>
        <div class="modal-card">
          <header class="modal-card-head">
            <p class="modal-card-title" id="edit-modal-title">{{ $t('teams.edit_team') }}</p>
            <button class="delete" :aria-label="$t('label.close')" @click="showEditModal = false"></button>
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
import { ensureKeyPair } from '@/services/keySharingService'

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

// Encrypted sharing state
const showShareModal      = ref(false)
const shareAccessLevel    = ref('read')
const shareAccountSecret  = ref('')
const selectedMemberIds   = ref([])
const isSharing           = ref(false)
const keyPairReady        = ref(false)
const isInitingKeys       = ref(false)

const userRole = computed(() => team.value?.role || 'viewer')
const isOwner = computed(() => userRole.value === 'owner')
const canUpdate = computed(() => ['owner', 'admin'].includes(userRole.value))
const canDelete = computed(() => isOwner.value)
const canInvite = computed(() => ['owner', 'admin'].includes(userRole.value))
const canManageMembers = computed(() => ['owner', 'admin'].includes(userRole.value))
const nonOwnerMembers = computed(() => (team.value?.members || []).filter(m => m.role !== 'owner'))

onMounted(async () => {
  await loadTeam()
  // Check key pair availability for encrypted sharing (non-blocking)
  ensureKeyPair().then(() => { keyPairReady.value = true }).catch(() => {})
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

async function initKeyPair() {
  isInitingKeys.value = true
  try {
    await ensureKeyPair()
    keyPairReady.value = true
    notifyStore.success('Key pair ready for encrypted sharing')
  } catch (error) {
    notifyStore.error('Failed to initialize key pair: ' + error.message)
  } finally {
    isInitingKeys.value = false
  }
}

async function handleShareEncrypted() {
  if (!shareAccountSecret.value || selectedMemberIds.value.length === 0) return
  isSharing.value = true
  try {
    const members = (team.value?.members || []).filter(m => selectedMemberIds.value.includes(m.id))
    // twofaccountId is unknown here — the owner enters the secret manually
    // For MVP: store the wrapped key against twofaccount_id = 0 (no account link)
    // In a future iteration, the owner selects from their account list
    await teamsStore.shareEncrypted(
      team.value.id,
      0,
      shareAccountSecret.value,
      members,
      shareAccessLevel.value
    )
    notifyStore.success('Account shared with encrypted keys')
    showShareModal.value = false
    shareAccountSecret.value = ''
    selectedMemberIds.value = []
    const shared = await teamsStore.fetchSharedAccounts(team.value.id)
    sharedAccounts.value = shared
  } catch (error) {
    notifyStore.error(error.message || 'Failed to share account')
  } finally {
    isSharing.value = false
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
