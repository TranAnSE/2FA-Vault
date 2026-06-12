import iconService from '@/services/iconService'
import { useUserStore } from '@/stores/user'
import { useNotify } from '@2fauth/ui'

/**
 * Composable for icon management in account forms.
 * Handles icon upload, deletion, logo fetching, and icon pack listing.
 *
 * @param {object} form - Reactive form instance
 * @param {object} options - { showQuickForm, OtpDisplayForQuickForm }
 */
export function useIconManager(form, options = {}) {
    const user = useUserStore()
    const notify = useNotify()
    const { t } = useI18n()

    const tempIcon = ref('')
    const fetchingLogo = ref(false)
    const iconCollection = ref(user.preferences.iconCollection)
    const iconCollectionVariant = ref(user.preferences.iconVariant)
    const iconPack = ref(user.preferences.iconPack ?? '/')
    const iconPacks = ref([{ text: 'label.no_available_icon_packs', value: '/' }])
    const hasSomeIconPack = ref(false)
    const isLoading = ref(false)

    const iconCollections = [
        { text: 'selfh.st', value: 'selfh', hasVariant: true },
        { text: 'dashboardicons.com', value: 'dashboardicons', hasVariant: true },
        { text: '2fa.directory', value: 'tfa', hasVariant: false },
    ]

    const iconCollectionVariants = {
        selfh: [
            { text: 'label.regular', value: 'regular' },
            { text: 'label.light', value: 'light' },
            { text: 'label.dark', value: 'dark' },
        ],
        dashboardicons: [
            { text: 'label.regular', value: 'regular' },
            { text: 'label.light', value: 'light' },
            { text: 'label.dark', value: 'dark' },
        ],
        tfa: [{ text: 'label.regular', value: 'regular' }],
    }

    watch(iconCollection, (val) => {
        iconCollectionVariant.value = Object.prototype.hasOwnProperty.call(iconCollectionVariants, val)
            ? iconCollectionVariants[val][0].value
            : ''
    })

    watch(tempIcon, (val) => {
        if (options.showQuickForm?.value) {
            nextTick().then(() => {
                options.OtpDisplayForQuickForm?.value && (options.OtpDisplayForQuickForm.value.icon = val)
            })
        }
    })

    /**
     * Uploads the submitted image resource to the backend
     */
    function uploadIcon(iconForm, iconInput) {
        deleteTempIcon()
        iconForm.icon = iconInput.files[0]

        iconForm.upload('/api/v1/icons', { returnError: true })
            .then(response => {
                tempIcon.value = response.data.filename
                if (options.showQuickForm?.value) {
                    form.icon = tempIcon.value
                }
            })
            .catch(error => {
                if (error.response.status !== 422) {
                    notify.alert({ text: error.response.data.message })
                }
            })
    }

    /**
     * Deletes the temp icon from backend
     */
    function deleteTempIcon(isEditMode) {
        if (isEditMode) {
            if (tempIcon.value) {
                if (tempIcon.value !== form.icon) {
                    iconService.deleteIcon(tempIcon.value)
                }
                tempIcon.value = ''
            }
        } else if (tempIcon.value) {
            iconService.deleteIcon(tempIcon.value)
            tempIcon.value = ''
            if (options.showQuickForm?.value) {
                form.icon = ''
            }
        }
    }

    /**
     * Tries to get the official logo/icon of the service filled in the form
     */
    function fetchLogo() {
        if (!user.preferences.getOfficialIcons) return

        fetchingLogo.value = true

        if (user.preferences.iconSource == 'logolib') {
            iconService.getLogo(form.service, iconCollection.value, iconCollectionVariant.value, { returnError: true })
                .then(response => {
                    if (response.status === 201) {
                        deleteTempIcon()
                        tempIcon.value = response.data.filename
                    }
                    else notify.warn({ text: t('error.no_icon_for_this_variant') })
                })
                .catch(() => {
                    notify.warn({ text: t('error.no_icon_for_this_variant') })
                })
                .finally(() => { fetchingLogo.value = false })
        } else {
            iconService.getLogoFromPack(form.service, iconPack.value, { returnError: true })
                .then(response => {
                    if (response.status === 201) {
                        deleteTempIcon()
                        tempIcon.value = response.data.filename
                    }
                    else notify.warn({ text: t('error.no_match_in_the_icon_pack') })
                })
                .catch(error => {
                    if (error.response.status === 422) {
                        form.clear()
                        form.errors.set(form.extractErrors(error.response))
                    }
                    else notify.warn({ text: t('error.no_match_in_the_icon_pack') })
                })
                .finally(() => { fetchingLogo.value = false })
        }
    }

    /**
     * Refreshes the list of available icon packs
     */
    function refreshIconPackList() {
        isLoading.value = true

        iconService.getIconPacks().then(response => {
            iconPacks.value = []
            response.data.forEach((pack) => {
                iconPacks.value.push({ text: pack.name, value: pack.name })
            })

            if (iconPacks.value.length == 0) {
                hasSomeIconPack.value = false
                iconPack.value = '/'
                iconPacks.value.push({ text: 'label.no_available_icon_packs', value: '/' })
            } else {
                hasSomeIconPack.value = true
                iconPack.value = user.preferences.iconPack == null || !iconPacks.value.some((pack) => pack.value === user.preferences.iconPack)
                    ? iconPacks.value[0].value
                    : user.preferences.iconPack
            }
        }).finally(() => { isLoading.value = false })
    }

    return {
        tempIcon,
        fetchingLogo,
        iconCollection,
        iconCollectionVariant,
        iconPack,
        iconPacks,
        hasSomeIconPack,
        isLoading,
        iconCollections,
        iconCollectionVariants,
        uploadIcon,
        deleteTempIcon,
        fetchLogo,
        refreshIconPackList,
    }
}
