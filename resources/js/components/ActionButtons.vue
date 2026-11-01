<script setup>
    import { UseColorMode  } from '@vueuse/components'
    import { useUserStore } from '@/stores/user'
    import { LucideScanQrCode } from 'lucide-vue-next'

    const router = useRouter()
    const user = useUserStore()

    const props = defineProps({
        showNew: {
            type: Boolean,
            default: true
        },
        showManage: {
            type: Boolean,
            default: true
        },
        canMove: {
            type: Boolean,
            default: true
        },
        showMove: {
            type: Boolean,
            default: false
        },
        canDelete: {
            type: Boolean,
            default: true
        },
        showDelete: {
            type: Boolean,
            default: false
        },
        canExport: {
            type: Boolean,
            default: true
        },
        showExport: {
            type: Boolean,
            default: false
        },
        areDisabled: {
            type: Boolean,
            default: false
        },
    })

    const emit = defineEmits(['move-button-clicked', 'delete-button-clicked', 'export-button-clicked', 'switch-to-management-mode'])

    /**
     * Routes user to the appropriate submitting view
     */
    function goAddNewAccount() {
        if( user.preferences.useDirectCapture && user.preferences.defaultCaptureMode === 'advancedForm' ) {
            router.push({ name: 'createAccount' })
        }
        else if( user.preferences.useDirectCapture && user.preferences.defaultCaptureMode === 'livescan' ) {
            router.push({ name: 'capture' })
        }
        else {
            router.push({ name: 'start' })
        }
    }
</script>

<template>
    <UseColorMode v-slot="{ mode }">
        <!-- New item buttons -->
        <p class="control" v-if="showNew">
            <button type="button" class="button is-link is-rounded is-focus" @click="goAddNewAccount">
                <span>{{ $t('label.new') }}</span>
                <span class="icon is-small">
                    <LucideScanQrCode />
                </span>
            </button>
        </p>
        <!-- Manage button -->
        <p class="control" v-if="showManage">
            <button type="button" id="btnManage" class="button is-rounded" :class="{'is-dark' : mode == 'dark'}" @click="$emit('switch-to-management-mode')">{{ $t('label.manage') }}</button>
        </p>
        <!-- move button -->
        <p class="control" v-if="showMove">
            <button
                id="btnMove"
                :disabled='areDisabled || !canMove' class="button is-rounded"
                :class="[{ 'is-outlined': mode == 'dark' || areDisabled }, areDisabled ? 'is-dark': 'is-link']"
                @click="$emit('move-button-clicked')"
                :title="$t('tooltip.move_selected_to_group')" >
                    {{ $t('label.move') }}
            </button>
        </p>
        <!-- delete button -->
        <p class="control" v-if="showDelete">
            <button
                id="btnDelete"
                :disabled='areDisabled || !canDelete' class="button is-rounded"
                :class="[{ 'is-outlined': mode == 'dark' || areDisabled }, areDisabled ? 'is-dark': 'is-link']"
                @click="$emit('delete-button-clicked')" >
                    {{ $t('label.delete') }}
            </button>
        </p>
        <!-- export button -->
        <p class="control" v-if="showExport">
            <button
                id="btnExport"
                :disabled='areDisabled || !canExport' class="button is-rounded"
                :class="[{ 'is-outlined': mode == 'dark' || areDisabled }, areDisabled ? 'is-dark': 'is-link']"
                @click="$emit('export-button-clicked')"
                :title="$t('tooltip.export_selected_accounts')" >
                    {{ $t('label.export') }}
            </button>
        </p>
    </UseColorMode>
</template>
