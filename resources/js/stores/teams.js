import { defineStore } from 'pinia'
import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export const useTeamsStore = defineStore('teams', {
  state: () => ({
    teams: [],
    currentTeam: null,
  }),

  getters: {
    getTeamById: (state) => (id) => {
      return state.teams.find(team => team.id === id)
    },
  },

  actions: {
    async fetchTeams() {
      try {
        const { data } = await apiClient.get('/api/v1/teams')
        this.teams = data
        return data
      } catch (error) {
        console.error('Failed to fetch teams:', error)
        throw error
      }
    },

    async fetchTeamDetail(teamId) {
      try {
        const { data } = await apiClient.get(`/api/v1/teams/${teamId}`)
        this.currentTeam = data
        return data
      } catch (error) {
        console.error('Failed to fetch team detail:', error)
        throw error
      }
    },

    async createTeam(teamData) {
      try {
        const { data } = await apiClient.post('/api/v1/teams', teamData)
        this.teams.push(data)
        return data
      } catch (error) {
        console.error('Failed to create team:', error)
        throw error
      }
    },

    async updateTeam(teamId, teamData) {
      try {
        const { data } = await apiClient.put(`/api/v1/teams/${teamId}`, teamData)
        
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
        await apiClient.delete(`/api/v1/teams/${teamId}`)
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
        const { data } = await apiClient.post(`/api/v1/teams/${teamId}/invite`)
        return data
      } catch (error) {
        console.error('Failed to generate invite code:', error)
        throw error
      }
    },

    async joinTeam(inviteCode) {
      try {
        const { data } = await apiClient.post('/api/v1/teams/join', { invite_code: inviteCode })
        this.teams.push(data.team)
        return data
      } catch (error) {
        console.error('Failed to join team:', error)
        throw error
      }
    },

    async leaveTeam(teamId) {
      try {
        await apiClient.post(`/api/v1/teams/${teamId}/leave`)
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
        await apiClient.delete(`/api/v1/teams/${teamId}/members/${userId}`)
        
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
        await apiClient.put(`/api/v1/teams/${teamId}/members/${userId}/role`, { role })
        
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
  },
})
