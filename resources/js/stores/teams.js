import { defineStore } from 'pinia'
import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export const useTeamsStore = defineStore('teams', {
  state: () => ({
    teams: [],
    currentTeam: null,
    sharedAccounts: [],
    pendingInvitations: [],
  }),

  getters: {
    getTeamById: (state) => (id) => {
      return state.teams.find(team => team.id === id)
    },
  },

  actions: {
    async fetchTeams() {
      try {
        const { data } = await apiClient.get('/teams')
        this.teams = data
        return data
      } catch (error) {
        console.error('Failed to fetch teams:', error)
        throw error
      }
    },

    async fetchTeamDetail(teamId) {
      try {
        const { data } = await apiClient.get(`/teams/${teamId}`)
        this.currentTeam = data
        return data
      } catch (error) {
        console.error('Failed to fetch team detail:', error)
        throw error
      }
    },

    async createTeam(teamData) {
      try {
        const { data } = await apiClient.post('/teams', teamData)
        this.teams.push(data)
        return data
      } catch (error) {
        console.error('Failed to create team:', error)
        throw error
      }
    },

    async updateTeam(teamId, teamData) {
      try {
        const { data } = await apiClient.put(`/teams/${teamId}`, teamData)
        
        const index = this.teams.findIndex(t => t.id === teamId)
        if (index !== -1) {
          this.teams[index] = { ...this.teams[index], ...data }
        }
        
        if (this.currentTeam?.id === teamId) {
          this.currentTeam = { ...this.currentTeam, ...data }
        }
        
        return data
      } catch (error) {
        console.error('Failed to update team:', error)
        throw error
      }
    },

    async deleteTeam(teamId) {
      try {
        await apiClient.delete(`/teams/${teamId}`)
        this.teams = this.teams.filter(t => t.id !== teamId)
        
        if (this.currentTeam?.id === teamId) {
          this.currentTeam = null
        }
      } catch (error) {
        console.error('Failed to delete team:', error)
        throw error
      }
    },

    async generateInviteCode(teamId) {
      try {
        const { data } = await apiClient.post(`/teams/${teamId}/invite`)
        return data
      } catch (error) {
        console.error('Failed to generate invite code:', error)
        throw error
      }
    },

    async joinTeam(inviteCode) {
      try {
        const { data } = await apiClient.post('/teams/join', { invite_code: inviteCode })
        this.teams.push(data.team)
        return data
      } catch (error) {
        console.error('Failed to join team:', error)
        throw error
      }
    },

    async leaveTeam(teamId) {
      try {
        await apiClient.post(`/teams/${teamId}/leave`)
        this.teams = this.teams.filter(t => t.id !== teamId)
        
        if (this.currentTeam?.id === teamId) {
          this.currentTeam = null
        }
      } catch (error) {
        console.error('Failed to leave team:', error)
        throw error
      }
    },

    async removeMember(teamId, userId) {
      try {
        await apiClient.delete(`/teams/${teamId}/members/${userId}`)
        
        if (this.currentTeam?.id === teamId) {
          this.currentTeam.members = this.currentTeam.members.filter(m => m.id !== userId)
        }
      } catch (error) {
        console.error('Failed to remove member:', error)
        throw error
      }
    },

    async updateMemberRole(teamId, userId, role) {
      try {
        await apiClient.put(`/teams/${teamId}/members/${userId}/role`, { role })

        if (this.currentTeam?.id === teamId) {
          const member = this.currentTeam.members.find(m => m.id === userId)
          if (member) {
            member.role = role
          }
        }
      } catch (error) {
        console.error('Failed to update member role:', error)
        throw error
      }
    },

    // --- Account Sharing ---

    async fetchSharedAccounts(teamId) {
      try {
        const { data } = await apiClient.get(`/teams/${teamId}/shared-accounts`)
        this.sharedAccounts = data
        return data
      } catch (error) {
        console.error('Failed to fetch shared accounts:', error)
        throw error
      }
    },

    async shareAccount(teamId, twofaccountId, accessLevel = 'read') {
      try {
        const { data } = await apiClient.post(`/teams/${teamId}/share`, {
          twofaccount_id: twofaccountId,
          access_level: accessLevel,
        })
        return data
      } catch (error) {
        console.error('Failed to share account:', error)
        throw error
      }
    },

    async unshareAccount(teamId, twofaccountId) {
      try {
        await apiClient.delete(`/teams/${teamId}/share/${twofaccountId}`)
        this.sharedAccounts = this.sharedAccounts.filter(sa => sa.twofaccount_id !== twofaccountId)
      } catch (error) {
        console.error('Failed to unshare account:', error)
        throw error
      }
    },

    // --- Email Invitations ---

    async inviteByEmail(teamId, email, role = 'member') {
      try {
        const { data } = await apiClient.post(`/teams/${teamId}/invite`, { email, role })
        return data
      } catch (error) {
        console.error('Failed to send email invitation:', error)
        throw error
      }
    },

    async fetchInvitations(teamId) {
      try {
        const { data } = await apiClient.get(`/teams/${teamId}/invitations`)
        this.pendingInvitations = data
        return data
      } catch (error) {
        console.error('Failed to fetch invitations:', error)
        throw error
      }
    },

    async cancelInvitation(teamId, invitationId) {
      try {
        await apiClient.delete(`/teams/${teamId}/invitations/${invitationId}`)
        this.pendingInvitations = this.pendingInvitations.filter(inv => inv.id !== invitationId)
      } catch (error) {
        console.error('Failed to cancel invitation:', error)
        throw error
      }
    },

    async acceptEmailInvitation(token) {
      try {
        const { data } = await apiClient.post(`/teams/invitations/${token}/accept`)
        return data
      } catch (error) {
        console.error('Failed to accept invitation:', error)
        throw error
      }
    },
  },
})
