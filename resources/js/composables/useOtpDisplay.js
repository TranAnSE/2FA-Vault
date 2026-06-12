import twofaccountService from '@/services/twofaccountService'
import { useUserStore } from '@/stores/user'
import { useTwofaccounts } from '@/stores/twofaccounts'
import { useBusStore } from '@/stores/bus'
import { useNotify } from '@2fauth/ui'

/**
 * Composable for OTP display logic — TOTP rotation, dots management, clipboard copy.
 *
 * @param {object} options - { dotsRefs, dotsControllers, opacities, renewedPeriod }
 */
export function useOtpDisplay(options = {}) {
    const user = useUserStore()
    const twofaccounts = useTwofaccounts()
    const bus = useBusStore()
    const notify = useNotify()
    const { t } = useI18n()
    const { copy, copied } = useClipboard({ legacy: true })

    const showOtpInModal = ref(false)
    const accountParams = ref({
        otp_type: '', account: '', service: '', icon: '',
        secret: '', digits: null, algorithm: '', period: null, counter: null, image: '',
    })

    /**
     * Turns dots On for all dots components that match the provided period
     */
    function turnDotsOn(period, stepIndex) {
        const dotsRefs = options.dotsRefs
        const opacities = options.opacities

        if (!dotsRefs || !dotsRefs.value) return
        dotsRefs.value
            .filter((dots) => dots.props.period == period || period == undefined)
            .forEach((dot) => { dot.turnOn(stepIndex) })

        if (opacities && opacities.value) {
            opacities.value[period] = 'is-opacity-' + stepIndex
        }
    }

    /**
     * Turns dots Off for all dots components that match the provided period
     */
    function turnDotsOff(period) {
        const dotsRefs = options.dotsRefs
        if (!dotsRefs || !dotsRefs.value) return
        dotsRefs.value
            .filter((dots) => dots.props.period == period || period == undefined)
            .forEach((dot) => { dot.turnOff() })
    }

    /**
     * Updates "Always On" OTPs for all TOTP accounts and (re)starts dots controllers
     */
    async function updateTotps(period) {
        const renewedPeriod = options.renewedPeriod
        const dotsControllers = options.dotsControllers

        let fetchPromise
        if (period == undefined) {
            if (renewedPeriod) renewedPeriod.value = -1
            fetchPromise = twofaccountService.getAll(true)
        } else {
            if (renewedPeriod) renewedPeriod.value = period
            fetchPromise = twofaccountService.getByIds(twofaccounts.accountIdsWithPeriod(period).join(','), true)
        }

        turnDotsOff(period)

        const totpAccountsWithNextPassword = twofaccounts.items.filter(
            (account) => account.otp_type.includes('totp') && account.period == period && account.otp.next_password
        )

        if (totpAccountsWithNextPassword.length > 0) {
            totpAccountsWithNextPassword.forEach((account) => {
                const index = twofaccounts.items.findIndex(acc => acc.id === account.id)
                if (twofaccounts.items[index].otp.next_password) {
                    twofaccounts.items[index].otp.password = twofaccounts.items[index].otp.next_password
                }
            })
            turnDotsOn(period, 0)
        }

        fetchPromise.then(response => {
            let generatedAt = 0
            response.data.forEach((account) => {
                if (account.otp_type.includes('totp')) {
                    const index = twofaccounts.items.findIndex(acc => acc.id === account.id)
                    if (twofaccounts.items[index] == undefined) {
                        twofaccounts.items.push(account)
                    } else twofaccounts.items[index].otp = account.otp
                    generatedAt = account.otp.generated_at
                }
            })

            if (dotsControllers && dotsControllers.value) {
                dotsControllers.value.forEach((dotsController) => {
                    if (dotsController.props.period == period || period == undefined) {
                        nextTick().then(() => { dotsController.startStepping(generatedAt) })
                    }
                })
            }
        }).finally(() => {
            if (renewedPeriod) renewedPeriod.value = null
        })
    }

    /**
     * Shows rotating OTP for the provided account
     */
    function showOTP(account) {
        accountParams.value.otp_type = account.otp_type
        accountParams.value.service = account.service
        accountParams.value.account = account.account
        accountParams.value.icon = account.icon
        nextTick().then(() => { showOtpInModal.value = true })
    }

    /**
     * Shows an OTP in a modal or directly copies it to the clipboard
     */
    function showOrCopy(account, otpDisplay) {
        if (bus.inManagementMode) {
            twofaccounts.select(account.id)
        } else {
            if (!user.preferences.getOtpOnRequest && account.otp_type.includes('totp')) {
                copyToClipboard(account.otp.password)
            } else {
                showOTP(account)
            }
        }
    }

    /**
     * Copies a string to the clipboard
     */
    function copyToClipboard(password) {
        copy(password)
        if (copied) {
            if (user.preferences.kickUserAfter == -1) { user.logout({ kicked: true }) }
            if (user.preferences.clearSearchOnCopy) { twofaccounts.filter = '' }
            if (user.preferences.viewDefaultGroupOnCopy) {
                user.preferences.activeGroup = user.preferences.defaultGroup == -1
                    ? user.preferences.activeGroup
                    : user.preferences.defaultGroup
            }
            notify.success({ text: t('notification.copied_to_clipboard') })
        }
    }

    /**
     * Gets a fresh OTP from backend and copies it
     */
    async function getAndCopyOTP(account) {
        twofaccountService.getOtpById(account.id).then(response => {
            let otp = response.data
            copyToClipboard(otp.password)
            if (otp.otp_type == 'hotp') {
                let hotpToIncrement = twofaccounts.items.find((acc) => acc.id == account.id)
                if (hotpToIncrement != undefined) { hotpToIncrement.counter = otp.counter }
            }
        })
    }

    return {
        showOtpInModal, accountParams,
        turnDotsOn, turnDotsOff, updateTotps,
        showOTP, showOrCopy, copyToClipboard, getAndCopyOTP,
    }
}
