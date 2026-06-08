<script setup>
    const props = defineProps({
        activities: { type: Array, default: () => [] },
    })

    const ACTION_LABELS = {
        'team.created':            'created the team',
        'team.updated':            'updated the team name',
        'team.deleted':            'deleted the team',
        'member.invited':          'invited',
        'member.joined':           'joined the team',
        'member.left':             'left the team',
        'member.removed':          'removed',
        'member.role_changed':     'changed role of',
        'account.shared':          'shared account',
        'account.unshared':        'unshared account',
        'invitation.cancelled':    'cancelled invitation',
    }

    const ACTION_COLORS = {
        'team.created':  'is-success',
        'team.deleted':  'is-danger',
        'member.joined': 'is-info',
        'member.left':   'is-warning',
        'member.removed':'is-danger',
        'account.shared':'is-link',
        'account.unshared': 'is-warning',
    }

    function describeAction(entry) {
        const label = ACTION_LABELS[entry.action] ?? entry.action
        const target = entry.target_user?.name ?? entry.target_account?.service ?? ''
        return target ? `${label} ${target}` : label
    }

    function actionColor(action) {
        return ACTION_COLORS[action] ?? 'is-light'
    }

    function timeAgo(dateStr) {
        const diff = Date.now() - new Date(dateStr).getTime()
        const mins  = Math.floor(diff / 60000)
        const hours = Math.floor(mins / 60)
        const days  = Math.floor(hours / 24)
        if (days > 0)  return `${days}d ago`
        if (hours > 0) return `${hours}h ago`
        if (mins > 0)  return `${mins}m ago`
        return 'just now'
    }
</script>

<template>
    <div class="activity-timeline">
        <div v-if="!activities.length" class="has-text-grey has-text-centered py-4">
            {{ $t('message.no_activity_yet') }}
        </div>
        <div v-for="entry in activities" :key="entry.id" class="activity-entry is-flex is-align-items-flex-start mb-3">
            <span class="tag mr-3 mt-1" :class="actionColor(entry.action)" style="min-width:10px;height:10px;border-radius:50%;padding:0"></span>
            <div class="is-flex-grow-1">
                <span class="has-text-weight-semibold">{{ entry.user?.name ?? '—' }}</span>
                <span class="ml-1">{{ describeAction(entry) }}</span>
                <span v-if="entry.metadata?.access_level" class="tag is-small is-light ml-1">{{ entry.metadata.access_level }}</span>
            </div>
            <span class="is-size-7 has-text-grey ml-3 is-flex-shrink-0">{{ timeAgo(entry.created_at) }}</span>
        </div>
    </div>
</template>

<style scoped>
.activity-entry:not(:last-child) {
    border-bottom: 1px solid #f5f5f5;
    padding-bottom: 0.75rem;
}
</style>
