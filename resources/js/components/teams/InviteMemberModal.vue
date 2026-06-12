<template>
    <div class="modal" :class="{ 'is-active': show }" role="dialog" aria-modal="true" aria-labelledby="invite-modal-title" @keydown.escape="$emit('close')">
        <div class="modal-background" @click="$emit('close')" aria-hidden="true"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title" id="invite-modal-title">{{ $t('teams.invite_member') }}</p>
                <button class="delete" :aria-label="$t('label.close')" @click="$emit('close')"></button>
            </header>
            <section class="modal-card-body">
                <!-- Invite Code -->
                <div class="field">
                    <label class="label">{{ $t('teams.invite_code') }}</label>
                    <div class="control has-icons-right">
                        <input class="input" type="text" :value="inviteCode" readonly>
                        <span class="icon is-right" style="pointer-events: all; cursor: pointer;" @click="$emit('copy-code')">
                            <i class="fa fa-copy"></i>
                        </span>
                    </div>
                </div>
                <button @click="$emit('generate-code')" class="button is-link">
                    {{ $t('teams.generate_new_code') }}
                </button>

                <hr />

                <!-- Email Invitation -->
                <p class="has-text-weight-semibold mb-3">{{ $t('teams.invite_by_email') }}</p>
                <div class="field">
                    <label class="label">{{ $t('teams.email') }}</label>
                    <div class="control">
                        <input class="input" type="email" v-model="inviteEmail" :placeholder="$t('teams.email_placeholder')" />
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
                <button @click="$emit('send-invitation', { email: inviteEmail, role: inviteRole })" class="button is-primary" :disabled="!inviteEmail || isSending">
                    <span class="icon" v-if="isSending"><i class="fa fa-spinner fa-pulse"></i></span>
                    <span>{{ $t('teams.send_invitation') }}</span>
                </button>
            </section>
        </div>
    </div>
</template>

<script setup>
    const props = defineProps({
        show: { type: Boolean, required: true },
        inviteCode: { type: String, default: '' },
        isSending: { type: Boolean, default: false },
    })

    defineEmits(['close', 'copy-code', 'generate-code', 'send-invitation'])

    const inviteEmail = ref('')
    const inviteRole = ref('member')
</script>
