<script setup>
    import twofaccountService from '@/services/twofaccountService'
    import userService from '@/services/userService'
    import { httpClientFactory } from '@/services/httpClientFactory'
    import DestinationGroupSelector from '@/components/DestinationGroupSelector.vue'
    import Toolbar from '@/components/Toolbar.vue'
    import ActionButtons from '@/components/ActionButtons.vue'
    import ExportButtons from '@/components/ExportButtons.vue'
    import { UseColorMode } from '@vueuse/components'
    import { useUserStore } from '@/stores/user'
    import { useSettingsBreakpoints } from '@/composables/breakpoints'
    import {
        useNotify, SearchBox, GroupChips, GroupSwitch, OtpDisplay, Dots, DotsController, TwoFAccountList, TwoFAccountListItem
    } from '@2fauth/ui'
    import { useBusStore } from '@/stores/bus'
    import { useTwofaccounts } from '@/stores/twofaccounts'
    import { useGroups } from '@/stores/groups'
    import { useI18n } from 'vue-i18n'
    import { useErrorHandler } from '@2fauth/stores'
    import { useOtpDisplay } from '@/composables/useOtpDisplay'
    import { useAccountSort } from '@/composables/useAccountSort'
    import { LucideChevronDown } from 'lucide-vue-next'

    const errorHandler = useErrorHandler()
    const { t } = useI18n()
    const $2fauth = inject('2fauth')
    const router = useRouter()
    const notify = useNotify()
    const user = useUserStore()
    const bus = useBusStore()
    const twofaccounts = useTwofaccounts()
    const groups = useGroups()

    // Dedicated api client for optimistic pin PATCH (twofaccountService exposes no generic update method)
    const apiClient = httpClientFactory('api')

    const showExportFormatSelector = ref(false)
    const showGroupSwitch = ref(false)
    const showDestinationGroupSelector = ref(false)
    const isDragging = ref(false)
    const revealPassword = ref(null)
    const opacities = ref({})

    const otpDisplay = ref(null)
    const dotsControllers = ref([])
    const dotsRefs = ref([])
    const renewedPeriod = ref(null)

    const { isDesktop } = useSettingsBreakpoints()

    // Composables
    const { startSortable, stopSortable } = useAccountSort()
    const {
        showOtpInModal, accountParams,
        turnDotsOn, turnDotsOff, updateTotps,
        showOrCopy, copyToClipboard, getAndCopyOTP,
    } = useOtpDisplay({ otpDisplay, dotsRefs, dotsControllers, opacities, renewedPeriod })

    const showAccounts = computed(() => {
        return !twofaccounts.isEmpty && !showGroupSwitch.value && !showDestinationGroupSelector.value
    })

    /**
     * Returns whether or not the desktop table layout must be used
    */
    const showDesktopTable = computed(() => {
        return isDesktop.value && user.preferences.useDesktopTableLayout
    })

    watch(showOtpInModal, (val) => {
        if (val == false) otpDisplay.value?.clearOTP()
    })

    // E8: when the vault locks (inactivity auto-lock), close any open OTP
    // modal and clear its plaintext so an unattended screen shows nothing.
    watch(() => bus.vaultLockedAt, () => {
        if (bus.vaultLockedAt > 0) {
            showOtpInModal.value = false
            otpDisplay.value?.clearOTP()
        }
    })

    // Sortable binds to #dv, which is a span in grid/list modes and a tbody
    // in desktop-table mode. The list is stopped and re-started on every
    // management-mode, layout or list change so a stale instance never
    // survives an element-type swap.
    watch(
        [() => bus.inManagementMode, () => showDesktopTable.value, () => twofaccounts.items],
        ([newInManagementMode, newShowDesktopTable, newTwofaccountItems]) => {
            if (newTwofaccountItems.length > 0 && (newInManagementMode || newShowDesktopTable)) {
                stopSortable()
                nextTick(() => {
                    startSortable()
                })
            }
            else {
                stopSortable()
            }
        }
    )

    // B9: entering the "shared with me" virtual group loads the shared slice
    // into the store (flagged is_shared); every other group view hides it.
    // Entering the "shared by me" virtual group loads the shared-by-me IDs
    // so the filtered getter can restrict the view to those accounts.
    watch(() => user.preferences.activeGroup, (val) => {
        if (parseInt(val) === -4) {
            twofaccounts.fetchSharedWithMe().catch(() => {})
        }
        else if (parseInt(val) === -3) {
            twofaccounts.fetchSharedByMe().catch(() => {})
        }
    }, { immediate: true })

    onMounted(async () => {
        if (!user.preferences.getOtpOnRequest) {
            updateTotps()
        } else {
            twofaccounts.fetch().then(() => {
                if (twofaccounts.backendWasNewer) {
                    notify.info({ text: t('notification.data_refreshed_to_reflect_server_changes'), duration: 10000 })
                }
            })
        }
        groups.fetch()
    })

    function postGroupAssignementUpdate() {
        twofaccounts.fetch()
        twofaccounts.selectNone()
        showDestinationGroupSelector.value = false
        notify.success({ text: t('notification.accounts_moved') })
    }

    async function deleteAccounts() {
        await twofaccounts.deleteSelected()
        if (twofaccounts.isEmpty) {
            bus.inManagementMode = false
            router.push({ name: 'start' })
        }
    }

    /**
     * Deletes the given account without touching the selection
     */
    async function deleteAccount(accountId) {
        await twofaccounts.delete(accountId)

        if (twofaccounts.isEmpty) {
            bus.inManagementMode = false
            router.push({ name: 'start' })
        }
    }

    function exitManagementMode() {
        bus.inManagementMode = false
        twofaccounts.selectNone()
    }

    function saveActiveGroup(newActiveGroupId) {
        twofaccounts.groupLessOnly = false
        if (user.preferences.activeGroup != newActiveGroupId) {
            user.preferences.activeGroup = newActiveGroupId
        }
        if (user.preferences.rememberActiveGroup) {
            userService.updatePreference('activeGroup', user.preferences.activeGroup)
        }
    }

    // Group chips quick-switching (upstream v8 UX). @2fauth/ui's GroupChips
    // hardcodes upstream pseudo-group IDs (-1 group-less, -2 shared by me,
    // -3 shared with me) while the fork uses its own virtual IDs
    // (Group::SHARED_BY_ME_ID = -3, Group::SHARED_WITH_ME_ID = -4) plus a
    // separate groupLessOnly store flag. This computed maps between the two
    // ID scopes so the shared component stays unmodified.
    const chipsActiveGroup = computed({
        get: () => {
            if (twofaccounts.groupLessOnly) return -1
            const activeGroup = parseInt(user.preferences.activeGroup)
            if (activeGroup === -3) return -2
            if (activeGroup === -4) return -3
            return activeGroup
        },
        set: (chipId) => {
            if (chipId === -1) {
                // Group-less is a store flag in the fork, activeGroup resets to All
                twofaccounts.groupLessOnly = true
                saveActiveGroup(0)
            }
            else if (chipId === -2) saveActiveGroup(-3)
            else if (chipId === -3) saveActiveGroup(-4)
            else saveActiveGroup(chipId)
        },
    })

    /**
     * Finds a store account by id (list-item emits carry ids only)
     */
    function findAccount(accountId) {
        return twofaccounts.items.find(a => a.id === accountId)
    }

    function onStart() { isDragging.value = true }
    function onEnd() { isDragging.value = false }

    /**
     * Toggles the is_pinned flag on an account optimistically.
     * Sends a PATCH to the backend; reverts on error.
     */
    function togglePin(account) {
        const previous = account.is_pinned
        account.is_pinned = !account.is_pinned
        apiPatchPin(account.id, account.is_pinned).then(() => {
            notify.success({ text: t('notification.pin_updated') })
        }).catch(() => {
            account.is_pinned = previous
            notify.alert({ text: t('notification.pin_update_failed') })
        })
    }

    function togglePinById(accountId) {
        const account = findAccount(accountId)
        if (account) togglePin(account)
    }

    function toggleFavoriteById(accountId) {
        const account = findAccount(accountId)
        if (account) twofaccounts.toggleFavorite(account)
    }

    function toggleReveal(accountId) {
        revealPassword.value = revealPassword.value === accountId ? null : accountId
    }

    async function apiPatchPin(id, isPinned) {
        // twofaccountService has no generic patch method; call the axios api client directly.
        return apiClient.patch('/twofaccounts/' + id, { is_pinned: isPinned }, { returnError: true })
    }
</script>

<template>
    <UseColorMode v-slot="{ mode }">
    <div>
        <StackLayout>
            <template #header>
                <div class="header" v-if="showAccounts || showGroupSwitch">
                    <div class="columns is-gapless is-mobile is-centered">
                        <div class="column is-three-quarters-mobile is-one-third-tablet is-one-quarter-desktop is-one-quarter-widescreen is-one-quarter-fullhd">
                            <SearchBox v-model:keyword="twofaccounts.filter"/>
                        </div>
                    </div>
                </div>
            </template>
            <template #subheader v-if="!showDestinationGroupSelector">
                <Teleport defer to="#table-toolbar" :disabled="!showDesktopTable">
                    <Toolbar v-if="bus.inManagementMode || showDesktopTable"
                        v-model:sortOrder="user.preferences.sortOrder"
                        :selectedCount="twofaccounts.selectedCount"
                        @clear-selected="twofaccounts.selectNone()"
                        @select-all="twofaccounts.selectAll()"
                        @sort-asc="twofaccounts.sortAsc()"
                        @sort-desc="twofaccounts.sortDesc()">
                    </Toolbar>
                </Teleport>
                <div v-if="!bus.inManagementMode" class="has-text-centered">
                    <div v-if="showGroupSwitch">
                        <button type="button" id="btnHideGroupSwitch" :title="$t('tooltip.hide_group_selector')" tabindex="1" class="button is-text is-like-text has-text-grey-dark" :class="{'has-text-grey' : mode != 'dark'}" @click.stop="showGroupSwitch = !showGroupSwitch">
                            {{ $t('label.select_accounts_to_show') }}
                        </button>
                    </div>
                    <div v-else>
                        <!-- useShare is always on: the fork has no runtime sharing toggle -->
                        <GroupChips v-if="user.preferences.useGroupChips"
                            v-model:active-group="chipsActiveGroup"
                            v-model:show-group-switch="showGroupSwitch"
                            v-model:show-favorites-only="twofaccounts.showFavoritesOnly"
                            :groups="groups.items"
                            :filteredCount="twofaccounts.filteredCount"
                            :useVirtualChips="user.preferences.showVirtualChips"
                            :useShare="true"
                            :useFavorites="user.preferences.enableFavorites"
                            :isRightAligned="showDesktopTable" />
                        <button v-else type="button" id="btnShowGroupSwitch" :title="$t('tooltip.show_group_selector')" tabindex="1" class="button is-text is-like-text has-text-grey-dark" :class="{'has-text-grey' : mode != 'dark'}" @click.stop="showGroupSwitch = !showGroupSwitch">
                            <template v-if="twofaccounts.groupLessOnly">{{ $t('label.group_less') }} ({{ twofaccounts.filteredCount }})&nbsp;</template>
                            <template v-else-if="groups.current">{{ groups.current }} ({{ twofaccounts.filteredCount }})&nbsp;</template>
                            <template v-else>{{ $t('label.all') }} ({{ twofaccounts.filteredCount }})&nbsp;</template>
                            <LucideChevronDown class="mt-1" />
                        </button>
                    </div>
                </div>
            </template>
            <template #content>
                <GroupSwitch v-if="showGroupSwitch" v-model:is-visible="showGroupSwitch" v-model:active-group="user.preferences.activeGroup" :groups="groups.items" @active-group-changed="saveActiveGroup" @show-group-less="twofaccounts.groupLessOnly = true">
                    <RouterLink :to="{ name: 'groups' }" >{{ $t('link.manage_groups') }}</RouterLink>
                </GroupSwitch>
                <DestinationGroupSelector v-if="showDestinationGroupSelector" v-model:showDestinationGroupSelector="showDestinationGroupSelector" v-model:selectedAccountsIds="twofaccounts.selectedIds" :groups="groups.items" @accounts-moved="postGroupAssignementUpdate"></DestinationGroupSelector>
                <div class="accounts-container" :class="[{ 'is-edit-mode': bus.inManagementMode }]" v-if="showAccounts">
                    <div class="columns is-gapless mb-0">
                        <div id="table-toolbar" class="column is-narrow ml-3"></div>
                        <div id="table-groupchips" class="column ml-6"></div>
                    </div>
                    <!-- accounts -->
                    <div class="accounts">
                        <TwoFAccountList :useDesktopTableLayout="showDesktopTable" :isCentered="user.preferences.displayMode === 'grid'">
                            <TwoFAccountListItem
                                v-for="account in twofaccounts.filtered"
                                v-model:selectedTwofaccountIds="twofaccounts.selectedIds"
                                :key="account.id"
                                :useDesktopTableLayout="showDesktopTable"
                                :colorScheme="mode"
                                :inManagementMode="bus.inManagementMode"
                                :storageRootPath="$2fauth.config.subdirectory"
                                :account="account"
                                :isPinned="account.is_pinned"
                                :revealed="revealPassword == account.id"
                                :preferences="user.preferences"
                                :nextOtpOpacityClass="opacities[account.period]"
                                @show-or-copy="(account) => showOrCopy(account)"
                                @get-and-copy-otp="(account) => getAndCopyOTP(account)"
                                @copy-to-clipboard="(pwd) => copyToClipboard(pwd)"
                                @toggle-is-favorite="(accountId) => toggleFavoriteById(accountId)"
                                @toggle-pin="(accountId) => togglePinById(accountId)"
                                @toggle-reveal="(accountId) => toggleReveal(accountId)"
                                @show-otp="(account) => showOrCopy(account)"
                                @delete-account-clicked="(accountId) => deleteAccount(accountId)">
                                <template #dots>
                                    <Dots v-if="account.otp_type.includes('totp')" ref="dotsRefs" :class="'is-inline-block'" :isCondensed="true" :period="account.period" />
                                </template>
                            </TwoFAccountListItem>
                        </TwoFAccountList>
                    </div>
                </div>
            </template>
            <template #footer v-if="showGroupSwitch">
                <VueFooter :show-buttons="true">
                    <NavigationButton action="close" :use-link-tag="false" @closed="showGroupSwitch = false" />
                </VueFooter>
            </template>
            <template #footer v-else-if="!showDestinationGroupSelector">
                <VueFooter>
                    <template #default>
                        <ActionButtons
                            :showNew="!bus.inManagementMode || showDesktopTable"
                            :showManage="!bus.inManagementMode && !showDesktopTable"
                            :showMove="bus.inManagementMode || showDesktopTable"
                            :showDelete="bus.inManagementMode || showDesktopTable"
                            :showExport="bus.inManagementMode || showDesktopTable"
                            :areDisabled="twofaccounts.hasNoneSelected"
                            :canMove="twofaccounts.hasSelected"
                            :canDelete="twofaccounts.hasSelected"
                            :canExport="twofaccounts.hasSelected"
                            @switch-to-management-mode="bus.inManagementMode = true"
                            @move-button-clicked="showDestinationGroupSelector = true"
                            @delete-button-clicked="deleteAccounts"
                            @export-button-clicked="showExportFormatSelector = true">
                        </ActionButtons>
                    </template>
                    <template #subpart v-if="bus.inManagementMode">
                        <button type="button" id="lnkExitEdit" class="button is-ghost is-like-text" @click.stop="exitManagementMode">{{ $t('label.done') }}</button>
                    </template>
                </VueFooter>
            </template>
        </StackLayout>
        <Modal v-model="showExportFormatSelector">
            <ExportButtons @export-twofauth-format="twofaccounts.export()" @export-otpauth-format="twofaccounts.export('otpauth')"></ExportButtons>
        </Modal>
        <Modal v-model="showOtpInModal">
            <OtpDisplay
                ref="otpDisplay"
                :accountParams="accountParams"
                :preferences="user.preferences"
                :twofaccountService="twofaccountService"
                :iconPathPrefix="$2fauth.config.subdirectory"
                @please-close-me="showOtpInModal = false"
                @please-clear-search="twofaccounts.filter = ''"
                @kickme="user.logout({ kicked: true})"
                @please-update-activeGroup="saveActiveGroup"
                @otp-copied-to-clipboard="notify.success({ text: t('notification.copied_to_clipboard') })"
                @error="(error) => errorHandler.show(error)"
            />
        </Modal>
        <span v-if="!user.preferences.getOtpOnRequest">
            <DotsController v-for="period in twofaccounts.periods" ref="dotsControllers" :key="period.period" :autostart="false" :period="period.period" :generated_at="period.generated_at" @stepping-ended="updateTotps(period.period)" @stepping-started="turnDotsOn(period.period, $event)" @stepped-up="turnDotsOn(period.period, $event)"></DotsController>
        </span>
    </div>
    </UseColorMode>
</template>
