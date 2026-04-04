<template>
  <div class="user-management">
    <div class="header">
      <h1 class="title">{{ $t('admin.user_management') }}</h1>
    </div>

    <div class="box filters">
      <div class="field is-horizontal">
        <div class="field-body">
          <div class="field">
            <label class="label">{{ $t('admin.filter_by_status') }}</label>
            <div class="control">
              <div class="select">
                <select v-model="filters.status" @change="loadUsers">
                  <option value="">{{ $t('admin.all_status') }}</option>
                  <option value="active">{{ $t('admin.active') }}</option>
                  <option value="inactive">{{ $t('admin.inactive') }}</option>
                </select>
              </div>
            </div>
          </div>

          <div class="field">
            <label class="label">{{ $t('admin.filter_by_role') }}</label>
            <div class="control">
              <div class="select">
                <select v-model="filters.role" @change="loadUsers">
                  <option value="">{{ $t('admin.all_roles') }}</option>
                  <option value="admin">{{ $t('admin.admin') }}</option>
                  <option value="user">{{ $t('admin.user') }}</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="isLoading" class="has-text-centered">
      <span class="icon is-large">
        <i class="fa fa-spinner fa-pulse"></i>
      </span>
    </div>

    <div v-else class="box">
      <table class="table is-fullwidth is-hoverable">
        <thead>
          <tr>
            <th>{{ $t('admin.id') }}</th>
            <th>{{ $t('admin.name') }}</th>
            <th>{{ $t('admin.email') }}</th>
            <th>{{ $t('admin.role') }}</th>
            <th>{{ $t('admin.status') }}</th>
            <th>{{ $t('admin.teams_count') }}</th>
            <th>{{ $t('admin.accounts_count') }}</th>
            <th>{{ $t('admin.created_at') }}</th>
            <th>{{ $t('admin.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in users.data" :key="user.id">
            <td>{{ user.id }}</td>
            <td>{{ user.name }}</td>
            <td>{{ user.email }}</td>
            <td>
              <span class="tag" :class="user.is_admin ? 'is-danger' : 'is-info'">
                {{ user.is_admin ? 'Admin' : 'User' }}
              </span>
            </td>
            <td>
              <span class="tag" :class="user.is_active ? 'is-success' : 'is-warning'">
                {{ user.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td>{{ user.teams_count || 0 }}</td>
            <td>{{ user.twofaccounts_count || 0 }}</td>
            <td>{{ formatDate(user.created_at) }}</td>
            <td>
              <div class="buttons">
                <button 
                  @click="viewUser(user)" 
                  class="button is-small is-info"
                  :title="$t('admin.view_details')"
                >
                  <span class="icon">
                    <i class="fa fa-eye"></i>
                  </span>
                </button>
                <button 
                  @click="toggleUserStatus(user)" 
                  class="button is-small"
                  :class="user.is_active ? 'is-warning' : 'is-success'"
                  :title="user.is_active ? $t('admin.deactivate') : $t('admin.activate')"
                >
                  <span class="icon">
                    <i class="fa" :class="user.is_active ? 'fa-ban' : 'fa-check'"></i>
                  </span>
                </button>
                <button 
                  @click="toggleAdminRole(user)" 
                  class="button is-small is-link"
                  :title="user.is_admin ? $t('admin.demote') : $t('admin.promote')"
                  :disabled="user.id === currentUserId"
                >
                  <span class="icon">
                    <i class="fa fa-user-shield"></i>
                  </span>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Pagination -->
      <nav v-if="users.last_page > 1" class="pagination" role="navigation">
        <button 
          class="pagination-previous" 
          @click="goToPage(users.current_page - 1)"
          :disabled="users.current_page === 1"
        >
          {{ $t('admin.previous') }}
        </button>
        <button 
          class="pagination-next"
          @click="goToPage(users.current_page + 1)"
          :disabled="users.current_page === users.last_page"
        >
          {{ $t('admin.next') }}
        </button>
        <ul class="pagination-list">
          <li v-for="page in visiblePages" :key="page">
            <button 
              class="pagination-link"
              :class="{ 'is-current': page === users.current_page }"
              @click="goToPage(page)"
            >
              {{ page }}
            </button>
          </li>
        </ul>
      </nav>
    </div>

    <!-- User Detail Modal -->
    <div class="modal" :class="{ 'is-active': showDetailModal }">
      <div class="modal-background" @click="showDetailModal = false"></div>
      <div class="modal-card">
        <header class="modal-card-head">
          <p class="modal-card-title">{{ $t('admin.user_details') }}</p>
          <button class="delete" @click="showDetailModal = false"></button>
        </header>
        <section v-if="selectedUser" class="modal-card-body">
          <div class="content">
            <p><strong>{{ $t('admin.id') }}:</strong> {{ selectedUser.id }}</p>
            <p><strong>{{ $t('admin.name') }}:</strong> {{ selectedUser.name }}</p>
            <p><strong>{{ $t('admin.email') }}:</strong> {{ selectedUser.email }}</p>
            <p><strong>{{ $t('admin.role') }}:</strong> {{ selectedUser.is_admin ? 'Admin' : 'User' }}</p>
            <p><strong>{{ $t('admin.status') }}:</strong> {{ selectedUser.is_active ? 'Active' : 'Inactive' }}</p>
            <p><strong>{{ $t('admin.teams') }}:</strong> {{ selectedUser.teams_count }}</p>
            <p><strong>{{ $t('admin.owned_teams') }}:</strong> {{ selectedUser.owned_teams_count }}</p>
            <p><strong>{{ $t('admin.accounts') }}:</strong> {{ selectedUser.twofaccounts_count }}</p>
            <p><strong>{{ $t('admin.created_at') }}:</strong> {{ formatDate(selectedUser.created_at) }}</p>
          </div>
        </section>
        <footer class="modal-card-foot">
          <button class="button" @click="showDetailModal = false">{{ $t('admin.close') }}</button>
        </footer>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { httpClientFactory } from '@/services/httpClientFactory'
import { useNotifyStore } from '@/stores/notify'
import { useUserStore } from '@/stores/user'

const apiClient = httpClientFactory('api')
const notifyStore = useNotifyStore()
const userStore = useUserStore()

const users = ref({ data: [], current_page: 1, last_page: 1 })
const isLoading = ref(true)
const filters = ref({ status: '', role: '' })
const showDetailModal = ref(false)
const selectedUser = ref(null)

const currentUserId = computed(() => userStore.id)

const visiblePages = computed(() => {
  const pages = []
  const current = users.value.current_page
  const last = users.value.last_page
  
  for (let i = Math.max(1, current - 2); i <= Math.min(last, current + 2); i++) {
    pages.push(i)
  }
  
  return pages
})

onMounted(async () => {
  await loadUsers()
})

async function loadUsers(page = 1) {
  isLoading.value = true
  try {
    const params = { page }
    if (filters.value.status) params.status = filters.value.status
    if (filters.value.role) params.role = filters.value.role
    
    const { data } = await apiClient.get('/api/v1/admin/users', { params })
    users.value = data
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to load users')
  } finally {
    isLoading.value = false
  }
}

function goToPage(page) {
  if (page >= 1 && page <= users.value.last_page) {
    loadUsers(page)
  }
}

function formatDate(dateString) {
  return new Date(dateString).toLocaleString()
}

async function viewUser(user) {
  try {
    const { data } = await apiClient.get(`/api/v1/admin/users/${user.id}`)
    selectedUser.value = data
    showDetailModal.value = true
  } catch (error) {
    notifyStore.error(error.response?.data?.message || 'Failed to load user details')
  }
}

async function toggleUserStatus(user) {
  const action = user.is_active ? 'deactivate' : 'activate'
  if (!confirm(`Are you sure you want to ${action} ${user.name}?`)) return
  
  try {
    if (user.is_active) {
      await apiClient.delete(`/api/v1/admin/users/${user.id}`)
    } else {
      await apiClient.put(`/api/v1/admin/users/${user.id}`, { is_active: true })
    }
    
    await loadUsers(users.value.current_page)
    notifyStore.success(`User ${action}d successfully`)
  } catch (error) {
    notifyStore.error(error.response?.data?.message || `Failed to ${action} user`)
  }
}

async function toggleAdminRole(user) {
  const action = user.is_admin ? 'demote' : 'promote'
  if (!confirm(`Are you sure you want to ${action} ${user.name}?`)) return
  
  try {
    await apiClient.put(`/api/v1/admin/users/${user.id}`, { is_admin: !user.is_admin })
    await loadUsers(users.value.current_page)
    notifyStore.success(`User ${action}d successfully`)
  } catch (error) {
    notifyStore.error(error.response?.data?.message || `Failed to ${action} user`)
  }
}
</script>

<style scoped>
.user-management {
  padding: 2rem;
  max-width: 1400px;
  margin: 0 auto;
}

.header {
  margin-bottom: 2rem;
}

.filters {
  margin-bottom: 1rem;
}

.pagination {
  margin-top: 1rem;
}
</style>
