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
            <span class="icon"><i class="fa fa-arrow-left"></i></span>
            {{ $t('teams.back_to_teams') }}
          </router-link>
          <h1 class="title">{{ team.name }}</h1>
          <span class="tag" :class="getRoleClass(userRole)">{{ roleDisplayName(userRole) }}</span>
        </div>

        <div class="actions">
          <button v-if="canInvite" @click="showInviteModal = true" class="button is-link">
            <span class="icon"><i class="fa fa-user-plus"></i></span>
            <span>{{ $t('teams.invite_member') }}</span>
          </button>
          <button v-if="canUpdate" @click="showEditModal = true" class="button is-info">
            <span class="icon"><i class="fa fa-edit"></i></span>
            <span>{{ $t('teams.edit_team') }}</span>
          </button>
          <button v-if="!isOwner" @click="leaveTeam" class="button is-warning">
            <span class="icon"><i class="fa fa-sign-out-alt"></i></span>
            <span>{{ $t('teams.leave_team') }}</span>
          </button>
          <button v-if="canDelete" @click="deleteTeam" class="button is-danger">
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
              <td><span class="tag" :class="getRoleClass(member.role)">{{ roleDisplayName(member.role) }}</span></td>
              <td>{{ formatDate(member.joined_at) }}</td>
              <td v-if="canManageMembers">
                <div class="buttons">
                  <div v-if="canChangeRole(member)" class="select is-small">
                    <select :value="member.role" @change="changeRole(member, $event.target.value)" :aria-label="t('teams.role')">
                      <option v-for="r in assignableRoles" :key="r.slug" :value="r.slug">{{ roleDisplayName(r.slug) }}</option>
                      <!-- A role the actor cannot assign (subset constraint, or a
                           role deleted since) still renders as a visible disabled
                           option instead of a blank select. -->
                      <option v-if="!assignableRoles.some(r => r.slug === member.role)" :value="member.role" disabled>{{ roleDisplayName(member.role) }}</option>
                    </select>
                  </div>
                  <button v-if="canRemoveMember(member)" @click="removeMember(member)" class="button is-small is-danger">
                    <span class="icon"><i class="fa fa-user-times"></i></span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Roles & Permissions Section -->
      <div class="box roles-section" data-testid="team-roles">
        <div class="is-flex is-justify-content-space-between is-align-items-center mb-3">
          <h2 class="subtitle mb-0">{{ $t('teams.roles_title') }}</h2>
          <button v-if="canManageRoles" class="button is-small is-primary" @click="openCreateRole" data-testid="role-create">
            <span class="icon"><i class="fa fa-plus"></i></span>
            <span>{{ $t('teams.role_create') }}</span>
          </button>
        </div>
        <table class="table is-fullwidth is-hoverable">
          <thead>
            <tr>
              <th>{{ $t('teams.role') }}</th>
              <th>{{ $t('teams.role_permissions') }}</th>
              <th>{{ $t('teams.role_members') }}</th>
              <th v-if="canManageRoles">{{ $t('teams.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in roles" :key="r.id">
              <td>
                <span class="tag" :class="getRoleClass(r.slug)">{{ roleDisplayName(r.slug) }}</span>
                <span v-if="r.is_system" class="tag is-light is-size-7 ml-1">{{ $t('teams.role_system') }}</span>
              </td>
              <td>
                <span v-for="p in r.permissions" :key="p" class="tag is-light is-size-7 mr-1">{{ $t(`teams.perm_${p.replace('.', '_')}`) }}</span>
                <span class="has-text-grey is-size-7">{{ $t('teams.role_owner_locked_note') }}</span>
              </td>
              <td>{{ r.members_count }}</td>
              <td v-if="canManageRoles">
                <div class="buttons">
                  <button v-if="!r.is_system" class="button is-small is-info" @click="openEditRole(r)">
                    <span class="icon"><i class="fa fa-edit"></i></span>
                  </button>
                  <button
                    v-if="!r.is_system"
                    class="button is-small is-danger"
                    :class="{ 'is-loading': isDeletingRole === r.id }"
                    @click="deleteRole(r)"
                    data-testid="role-delete"
                  >
                    <span class="icon"><i class="fa fa-trash"></i></span>
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
              <td><span class="tag" :class="getRoleClass(inv.role)">{{ roleDisplayName(inv.role) }}</span></td>
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

      <!-- Extracted Modal Components -->
      <InviteMemberModal
        :show="showInviteModal"
        :inviteCode="inviteCode"
        :isSending="isSendingInvitation"
        @close="showInviteModal = false"
        @copy-code="copyInviteCode"
        @generate-code="generateNewInviteCode"
        @send-invitation="sendEmailInvitation"
      />

      <ShareAccountModal
        :show="showShareModal"
        :keyPairReady="keyPairReady"
        :isInitingKeys="isInitingKeys"
        :isSharing="isSharing"
        :members="nonOwnerMembers"
        :accounts="sharableAccounts"
        @close="showShareModal = false"
        @init-key-pair="initKeyPair"
        @share="handleShareEncrypted"
      />

      <EditTeamModal
        :show="showEditModal"
        :name="editTeamName"
        @close="showEditModal = false"
        @save="updateTeam"
      />

      <!-- Role editor modal (permission matrix grid) -->
      <div class="modal" :class="{ 'is-active': showRoleModal }" role="dialog" aria-modal="true" aria-labelledby="role-modal-title" data-testid="role-modal">
        <div class="modal-background" @click="cancelRoleModal" aria-hidden="true"></div>
        <div class="modal-card">
          <header class="modal-card-head">
            <p class="modal-card-title" id="role-modal-title">{{ editingRole ? $t('teams.role_edit_title') : $t('teams.role_create_title') }}</p>
            <button class="delete" @click="cancelRoleModal" :aria-label="$t('label.close')"></button>
          </header>
          <section class="modal-card-body">
            <div class="field" v-if="!editingRole">
              <label class="label is-size-7">{{ $t('teams.role_slug') }}</label>
              <input class="input" type="text" v-model="roleForm.slug" placeholder="auditor" maxlength="30" data-testid="role-slug-input" />
              <p class="help">{{ $t('teams.role_slug_help') }}</p>
            </div>
            <div class="field">
              <label class="label is-size-7">{{ $t('teams.role_name') }}</label>
              <input class="input" type="text" v-model="roleForm.name" maxlength="50" data-testid="role-name-input" />
            </div>

            <div class="field">
              <label class="label is-size-7">{{ $t('teams.role_permissions') }}</label>
              <div v-for="p in PERMISSIONS" :key="p" class="mb-1">
                <label class="checkbox">
                  <input
                    type="checkbox"
                    :checked="roleForm.permissions.includes(p)"
                    @change="togglePermission(p)"
                    :data-testid="`perm-${p.replace('.', '-')}`"
                  />
                  <strong class="is-size-7">{{ $t(`teams.perm_${p.replace('.', '_')}`) }}</strong>
                </label>
                <p class="help ml-5 is-size-7">{{ $t(`teams.perm_${p.replace('.', '_')}_desc`) }}</p>
              </div>
              <p class="help">
                <span class="has-text-grey">{{ $t('teams.role_owner_locked_list', { list: OWNER_LOCKED.map(p => $t(`teams.perm_${p.replace('.', '_')}`)).join(', ') }) }}</span>
              </p>
            </div>
          </section>
          <footer class="modal-card-foot">
            <button
              class="button is-primary"
              :class="{ 'is-loading': isSavingRole }"
              :disabled="isSavingRole || !roleForm.name.trim() || (!editingRole && !/^[a-z0-9-]{2,30}$/.test(roleForm.slug))"
              @click="saveRole"
              data-testid="role-save"
            >
              {{ $t('label.save') }}
            </button>
            <button class="button" @click="cancelRoleModal">{{ $t('label.cancel') }}</button>
          </footer>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ensureKeyPair } from '@/services/keySharingService'
import InviteMemberModal from '@/components/teams/InviteMemberModal.vue'
import ShareAccountModal from '@/components/teams/ShareAccountModal.vue'
import EditTeamModal from '@/components/teams/EditTeamModal.vue'
import teamRoleService from '@/services/teamRoleService'

const route = useRoute()
const router = useRouter()
const teamsStore = useTeamsStore()
const notifyStore = useNotifyStore()
const userStore = useUserStore()
const twofaccountsStore = useTwofaccounts()
const cryptoStore = useCryptoStore()
const { t } = useI18n()

// The closed permission catalog — MUST mirror App\Support\TeamPermission.
const PERMISSIONS = [
    'members.invite',
    'members.remove',
    'roles.assign',
    'accounts.share',
    'accounts.unshare_others',
    'team.edit',
    'activity.view',
]
// Owner-locked capabilities are shown greyed in the matrix editor (never
// grantable) and are not part of the editable grid.
const OWNER_LOCKED = ['team.delete', 'roles.manage', 'activity.export']

const SYSTEM_ROLES = ['owner', 'admin', 'member', 'viewer']

const team = ref(null)
const isLoading = ref(true)
const showInviteModal = ref(false)
const showEditModal = ref(false)
const editTeamName = ref('')
const inviteCode = ref('')
const isSendingInvitation = ref(false)
const sharedAccounts = ref([])
const pendingInvitations = ref([])
const showShareModal = ref(false)
const isSharing = ref(false)
const keyPairReady = ref(false)
const isInitingKeys = ref(false)

// Roles (permission matrix)
const roles = ref([])
const showRoleModal = ref(false)
const editingRole = ref(null)
const roleForm = ref({ slug: '', name: '', permissions: [] })
const isSavingRole = ref(false)
const isDeletingRole = ref(null)

// ---- Permission-derived gating (RT-11): never gate on role STRINGS. ----
const isOwner = computed(() => team.value?.owner_id === userStore.id)

const userRole = computed(() => team.value?.role || 'viewer')

const effectivePermissions = computed(() => {
    if (!team.value) return []
    if (isOwner.value) return [...PERMISSIONS]

    const roleDefinition = roles.value.find(r => r.slug === userRole.value)
    return roleDefinition?.permissions ?? []
})

function hasPerm(permission) {
    return effectivePermissions.value.includes(permission)
}

const canUpdate = computed(() => hasPerm('team.edit'))
const canInvite = computed(() => hasPerm('members.invite'))
const canRemoveMembers = computed(() => hasPerm('members.remove'))
const canAssignRoles = computed(() => hasPerm('roles.assign'))
const canManageRoles = computed(() => isOwner.value)
const canDelete = computed(() => isOwner.value)

// Roles the current user may ASSIGN to members: any team role whose
// permission set is a subset of the user's own effective permissions
// (owner exempt — mirrors the server-side RT-15 subset constraint).
const assignableRoles = computed(() => {
    if (isOwner.value) return roles.value.filter(r => r.slug !== 'owner')

    const mine = new Set(effectivePermissions.value)
    return roles.value.filter(r => r.slug !== 'owner' && (r.permissions || []).every(p => mine.has(p)))
})

const canManageMembers = computed(() => canAssignRoles.value || canRemoveMembers.value)

const nonOwnerMembers = computed(() => (team.value?.members || []).filter(m => m.id !== team.value?.owner_id))
// B1: the owner's own accounts, offered in the share modal. Already-shared
// accounts are excluded so they cannot be shared twice with stale keys.
const sharableAccounts = computed(() => {
    const sharedIds = new Set((sharedAccounts.value || []).map(sa => sa.twofaccount_id ?? sa.id))

    return (twofaccountsStore.items || []).filter(account => !sharedIds.has(account.id))
})

onMounted(async () => {
  await loadTeam()
  ensureKeyPair().then(() => { keyPairReady.value = true }).catch(() => {})
  // B1: the share modal lists the owner's own accounts; make sure they are
  // loaded (no-op when the store is already populated).
  twofaccountsStore.fetch().catch(() => {})
})

async function loadTeam() {
  isLoading.value = true
  try {
    team.value = await teamsStore.fetchTeamDetail(route.params.id)
    editTeamName.value = team.value.name
    inviteCode.value = team.value.invite_code || ''
    const [shared, invites, teamRoles] = await Promise.allSettled([
      teamsStore.fetchSharedAccounts(route.params.id),
      teamsStore.fetchInvitations(route.params.id),
      teamRoleService.list(route.params.id),
    ])
    sharedAccounts.value = shared.status === 'fulfilled' ? shared.value : []
    pendingInvitations.value = invites.status === 'fulfilled' ? invites.value : []
    roles.value = teamRoles.status === 'fulfilled' ? teamRoles.value.data : []
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to load team')
    router.push('/teams')
  } finally { isLoading.value = false }
}

function getRoleClass(role) {
  const classes = { owner: 'is-danger', admin: 'is-warning', member: 'is-info', viewer: 'is-light' }
  return classes[role] || 'is-info is-light'
}

// System slugs render translated names; custom roles carry their own.
function roleDisplayName(slug) {
  if (SYSTEM_ROLES.includes(slug)) return t(`teams.role_${slug}`)
  return roles.value.find(r => r.slug === slug)?.name || slug
}

function formatDate(dateString) { return new Date(dateString).toLocaleDateString() }

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

async function updateTeam(newName) {
  try {
    await teamsStore.updateTeam(team.value.id, { name: newName })
    team.value.name = newName
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
  } catch (error) { notifyStore.error(error.response?.data?.message || 'Failed to leave team') }
}

async function deleteTeam() {
  if (!confirm('Are you sure you want to delete this team? This action cannot be undone.')) return
  try {
    await teamsStore.deleteTeam(team.value.id)
    notifyStore.success('Team deleted successfully')
    router.push('/teams')
  } catch (error) { notifyStore.error(error.response?.data?.message || 'Failed to delete team') }
}

function canChangeRole(member) { return canAssignRoles.value && member.id !== team.value?.owner_id && assignableRoles.value.length > 0 }
function canRemoveMember(member) { return canRemoveMembers.value && member.id !== team.value?.owner_id }

async function changeRole(member, newRole) {
  if (!newRole || newRole === member.role) return
  try {
    await teamsStore.updateMemberRole(team.value.id, member.id, newRole)
    member.role = newRole
    notifyStore.success(t('teams.role_assigned'))
  } catch (error) { notifyStore.error(error.response?.data?.message || t('teams.role_assign_failed')) }
}

// ---- Roles (permission matrix) ----

function openCreateRole() {
  editingRole.value = null
  roleForm.value = { slug: '', name: '', permissions: [] }
  showRoleModal.value = true
}

function openEditRole(role) {
  editingRole.value = role
  roleForm.value = { slug: role.slug, name: role.name, permissions: [...(role.permissions || [])] }
  showRoleModal.value = true
}

function cancelRoleModal() {
  showRoleModal.value = false
  editingRole.value = null
}

async function saveRole() {
  if (!roleForm.value.name.trim()) return
  isSavingRole.value = true
  try {
    if (editingRole.value) {
      await teamRoleService.update(team.value.id, editingRole.value.id, {
        name: roleForm.value.name,
        permissions: roleForm.value.permissions,
      })
    } else {
      await teamRoleService.create(team.value.id, {
        slug: roleForm.value.slug,
        name: roleForm.value.name,
        permissions: roleForm.value.permissions,
      })
    }
    roles.value = (await teamRoleService.list(team.value.id)).data
    showRoleModal.value = false
    notifyStore.success(t(editingRole.value ? 'teams.role_updated' : 'teams.role_created'))
  } catch (error) {
    notifyStore.error(error.response?.data?.message || t('teams.role_save_failed'))
  } finally { isSavingRole.value = false }
}

async function deleteRole(role) {
  if (!confirm(t('teams.role_delete_confirm', { name: role.name }))) return
  isDeletingRole.value = role.id
  try {
    await teamRoleService.remove(team.value.id, role.id)
    roles.value = (await teamRoleService.list(team.value.id)).data
    notifyStore.success(t('teams.role_deleted'))
  } catch (error) {
    notifyStore.error(error.response?.data?.message || t('teams.role_delete_failed'))
  } finally { isDeletingRole.value = null }
}

function togglePermission(permission) {
  const set = new Set(roleForm.value.permissions)
  set.has(permission) ? set.delete(permission) : set.add(permission)
  roleForm.value.permissions = [...set]
}

async function removeMember(member) {
  if (!confirm(`Remove ${member.name} from the team?`)) return
  try {
    await teamsStore.removeMember(team.value.id, member.id)
    team.value.members = team.value.members.filter(m => m.id !== member.id)
    notifyStore.success('Member removed')
  } catch (error) { notifyStore.error(error.response?.data?.message || 'Failed to remove member') }
}

async function handleUnshareAccount(sa) {
  if (!confirm(`Unshare ${sa.account_service} (${sa.account_name}) from this team?`)) return
  try {
    await teamsStore.unshareAccount(team.value.id, sa.twofaccount_id)
    sharedAccounts.value = sharedAccounts.value.filter(s => s.id !== sa.id)
    notifyStore.success('Account unshared')
  } catch (error) { notifyStore.error(error.response?.data?.message || 'Failed to unshare account') }
}

async function sendEmailInvitation({ email, role }) {
  if (!email) return
  isSendingInvitation.value = true
  try {
    await teamsStore.inviteByEmail(team.value.id, email, role)
    const invites = await teamsStore.fetchInvitations(team.value.id)
    pendingInvitations.value = invites
    notifyStore.success('Invitation sent successfully')
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to send invitation')
  } finally { isSendingInvitation.value = false }
}

async function cancelInvitation(inv) {
  if (!confirm(`Cancel invitation to ${inv.email}?`)) return
  try {
    await teamsStore.cancelInvitation(team.value.id, inv.id)
    pendingInvitations.value = pendingInvitations.value.filter(i => i.id !== inv.id)
    notifyStore.success('Invitation cancelled')
  } catch (error) { notifyStore.error(error.response?.data?.message || 'Failed to cancel invitation') }
}

async function initKeyPair() {
  isInitingKeys.value = true
  try {
    await ensureKeyPair()
    keyPairReady.value = true
    notifyStore.success('Key pair ready for encrypted sharing')
  } catch (error) {
    notifyStore.error('Failed to initialize key pair: ' + error.message)
  } finally { isInitingKeys.value = false }
}

async function handleShareEncrypted({ accountId, memberIds, accessLevel }) {
  if (!accountId || memberIds.length === 0) return
  isSharing.value = true
  try {
    const account = (twofaccountsStore.items || []).find(a => a.id === accountId)
    if (!account) throw new Error('Account not found')

    // B1: decrypt client-side (E2EE accounts) or take the plaintext secret,
    // then let the sharing service wrap it per member with RSA-OAEP.
    let decryptedSecret = account.secret
    if (account.encrypted) {
      if (!cryptoStore.isUnlocked) throw new Error('Unlock the vault before sharing an encrypted account')
      const decrypted = await cryptoStore.decryptAccountData(account)
      decryptedSecret = decrypted.secret
    }
    if (!decryptedSecret) throw new Error('The selected account has no secret to share')

    const members = (team.value?.members || []).filter(m => memberIds.includes(m.id))
    await teamsStore.shareEncrypted(team.value.id, accountId, decryptedSecret, members, accessLevel)
    notifyStore.success('Account shared with encrypted keys')
    showShareModal.value = false
    const shared = await teamsStore.fetchSharedAccounts(team.value.id)
    sharedAccounts.value = shared
  } catch (error) {
    notifyStore.error(error.message || 'Failed to share account')
  } finally { isSharing.value = false }
}
</script>

<style scoped>
.team-detail { padding: 2rem; max-width: 1200px; margin: 0 auto; }
.header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem; }
.back-link { display: inline-flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
.title { margin-bottom: 0.5rem; }
.actions { display: flex; gap: 0.5rem; }
.members-section, .invitations-section, .shared-accounts-section, .roles-section { margin-top: 2rem; }
.subtitle { margin-bottom: 1rem; }
</style>
