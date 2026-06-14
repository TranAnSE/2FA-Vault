<script setup>
    import secureNotesService from '@/services/secureNotesService'
    import { useCryptoStore } from '@/stores/crypto'
    import { useNotify } from '@2fauth/ui'
    import { LucidePlus, LucidePin, LucideSearch, LucideLock, LucideTrash2 } from 'lucide-vue-next'

    const { t } = useI18n()
    const router = useRouter()
    const cryptoStore = useCryptoStore()
    const notify = useNotify()

    const notes = ref([])
    const isLoading = ref(false)
    const search = ref('')

    const isUnlocked = computed(() => cryptoStore.isVaultUnlocked)

    const filtered = computed(() => {
        if (!search.value) return notes.value
        const q = search.value.toLowerCase()
        return notes.value.filter(n => (n.titleDecrypted ?? '').toLowerCase().includes(q))
    })

    onMounted(load)

    async function load() {
        isLoading.value = true
        try {
            const { data } = await secureNotesService.list().catch(() => ({ data: [] }))
            const list = Array.isArray(data) ? data : (data.data ?? [])
            if (isUnlocked.value) {
                notes.value = await Promise.all(list.map(decryptMeta))
            } else {
                notes.value = list.map(n => ({ ...n, titleDecrypted: '' }))
            }
        } finally {
            isLoading.value = false
        }
    }

    async function decryptMeta(n) {
        let titleDecrypted = ''
        try {
            titleDecrypted = await cryptoStore.decryptData(n.title)
        } catch { titleDecrypted = '' }
        return { ...n, titleDecrypted }
    }

    async function removeNote(n) {
        if (!confirm(t('confirmation.delete_note'))) return
        try {
            await secureNotesService.remove(n.id)
            notes.value = notes.value.filter(x => x.id !== n.id)
            notify.success({ text: t('notification.note_deleted') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }

    async function togglePin(n) {
        const prev = n.is_pinned
        n.is_pinned = !n.is_pinned
        try {
            await secureNotesService.update(n.id, { is_pinned: n.is_pinned })
        } catch {
            n.is_pinned = prev
            notify.alert({ text: t('error.unknown') })
        }
    }
</script>

<template>
    <div class="container py-5">
        <div class="level mb-4">
            <div class="level-left">
                <h2 class="title is-3 mb-0">{{ $t('title.secure_notes') }}</h2>
            </div>
            <div class="level-right">
                <VueButton class="button is-primary" :disabled="!isUnlocked" @click="router.push({ name: 'createNote' })">
                    <LucidePlus class="mr-1" />{{ $t('label.new_note') }}
                </VueButton>
            </div>
        </div>

        <div v-if="!isUnlocked" class="notification is-warning is-light mb-4">
            <LucideLock class="mr-1" />{{ $t('message.notes_locked') }}
        </div>

        <div class="field mb-4">
            <p class="control has-icons-left">
                <input class="input" type="text" v-model="search" :disabled="!isUnlocked" :placeholder="$t('placeholder.search_notes')" />
                <span class="icon is-small is-left"><LucideSearch /></span>
            </p>
        </div>

        <div v-if="isLoading" class="has-text-grey">{{ $t('label.loading') }}</div>

        <div v-else-if="!filtered.length" class="notification is-light has-text-grey">
            {{ $t('message.no_notes_yet') }}
        </div>

        <div v-else class="box">
            <div v-for="n in filtered" :key="n.id" class="is-flex is-align-items-center is-justify-content-space-between py-2" style="border-bottom:1px solid #f0f0f0">
                <div class="is-flex is-align-items-center">
                    <button class="button is-ghost is-small mr-2" :class="{ 'has-text-warning': n.is_pinned }" :title="$t('tooltip.pin_note')" @click="togglePin(n)">
                        <LucidePin />
                    </button>
                    <RouterLink :to="{ name: 'editNote', params: { noteId: n.id } }" class="has-text-weight-semibold">
                        {{ n.titleDecrypted || $t('message.untitled_note') }}
                    </RouterLink>
                    <span class="tag is-light is-size-7 ml-2">{{ n.content_type || 'plain' }}</span>
                </div>
                <div class="buttons are-small">
                    <RouterLink :to="{ name: 'editNote', params: { noteId: n.id } }" class="button is-light">{{ $t('label.edit') }}</RouterLink>
                    <button class="button is-danger is-light" @click="removeNote(n)"><LucideTrash2 /></button>
                </div>
            </div>
        </div>
    </div>
</template>
