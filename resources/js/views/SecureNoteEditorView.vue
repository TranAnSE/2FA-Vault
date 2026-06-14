<script setup>
    import secureNotesService from '@/services/secureNotesService'
    import { useCryptoStore } from '@/stores/crypto'
    import { useNotify } from '@2fauth/ui'
    import { useMarkdownRenderer } from '@/composables/useMarkdownRenderer'
    import { LucidePin, LucideTrash2 } from 'lucide-vue-next'

    const { t } = useI18n()
    const router = useRouter()
    const route = useRoute()
    const cryptoStore = useCryptoStore()
    const notify = useNotify()
    const { renderNote } = useMarkdownRenderer()

    const props = defineProps({ noteId: [Number, String] })

    const isEdit = computed(() => !!props.noteId)
    const title = ref('')
    const content = ref('')
    const contentType = ref('markdown')
    const isPinned = ref(false)
    const mode = ref('edit') // 'edit' | 'preview'
    const isLoading = ref(false)
    const isSaving = ref(false)

    const previewHtml = computed(() => renderNote(content.value, contentType.value))

    onMounted(load)

    async function load() {
        if (!isEdit.value) return
        if (!cryptoStore.isVaultUnlocked) {
            notify.alert({ text: t('message.notes_locked') })
            router.push({ name: 'secureNotes' })
            return
        }
        isLoading.value = true
        try {
            const { data } = await secureNotesService.get(props.noteId)
            title.value = await safeDecrypt(data.title)
            content.value = await safeDecrypt(data.content)
            contentType.value = data.content_type || 'plain'
            isPinned.value = !!data.is_pinned
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
            router.push({ name: 'secureNotes' })
        } finally {
            isLoading.value = false
        }
    }

    async function safeDecrypt(value) {
        try { return await cryptoStore.decryptData(value) } catch { return '' }
    }

    async function save() {
        if (!cryptoStore.isVaultUnlocked) {
            notify.alert({ text: t('message.notes_locked') })
            return
        }
        if (!title.value.trim() && !content.value.trim()) return
        isSaving.value = true
        try {
            const encTitle = await cryptoStore.encryptData(title.value)
            const encContent = await cryptoStore.encryptData(content.value)
            const payload = {
                title: encTitle,
                content: encContent,
                content_type: contentType.value,
                is_pinned: isPinned.value,
            }
            if (isEdit.value) {
                await secureNotesService.update(props.noteId, payload)
                notify.success({ text: t('notification.note_updated') })
            } else {
                await secureNotesService.create(payload)
                notify.success({ text: t('notification.note_created') })
            }
            router.push({ name: 'secureNotes' })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        } finally {
            isSaving.value = false
        }
    }

    async function remove() {
        if (!isEdit.value) return
        if (!confirm(t('confirmation.delete_note'))) return
        try {
            await secureNotesService.remove(props.noteId)
            notify.success({ text: t('notification.note_deleted') })
            router.push({ name: 'secureNotes' })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }
</script>

<template>
    <div class="container py-5">
        <div class="level mb-4">
            <div class="level-left">
                <h2 class="title is-3 mb-0">{{ isEdit ? $t('title.edit_note') : $t('title.new_note') }}</h2>
            </div>
            <div class="level-right">
                <label class="checkbox is-size-6">
                    <input type="checkbox" v-model="isPinned" />
                    <LucidePin class="ml-1" :class="{ 'has-text-warning': isPinned }" />
                    {{ $t('label.pin_note') }}
                </label>
            </div>
        </div>

        <div v-if="isLoading" class="has-text-grey">{{ $t('label.loading') }}</div>

        <div v-else>
            <div class="field">
                <label class="label is-size-7">{{ $t('field.note_title') }}</label>
                <input class="input" type="text" v-model="title" :placeholder="$t('placeholder.note_title')" maxlength="255" />
            </div>

            <div class="field">
                <label class="label is-size-7">{{ $t('field.note_content_type') }}</label>
                <div class="select">
                    <select v-model="contentType">
                        <option value="plain">{{ $t('label.content_type_plain') }}</option>
                        <option value="markdown">{{ $t('label.content_type_markdown') }}</option>
                    </select>
                </div>
            </div>

            <div class="buttons mb-2">
                <button class="button is-small" :class="mode === 'edit' ? 'is-info' : 'is-light'" @click="mode = 'edit'">{{ $t('label.edit') }}</button>
                <button class="button is-small" :class="mode === 'preview' ? 'is-info' : 'is-light'" @click="mode = 'preview'">{{ $t('label.preview') }}</button>
            </div>

            <div v-if="mode === 'edit'" class="field">
                <textarea class="textarea" rows="14" v-model="content" :placeholder="$t('placeholder.note_content')"></textarea>
            </div>
            <div v-else class="content box note-preview" v-html="previewHtml"></div>

            <div class="buttons mt-4">
                <VueButton class="button is-primary" :isLoading="isSaving" @click="save">{{ $t('label.save') }}</VueButton>
                <VueButton class="button" @click="router.push({ name: 'secureNotes' })">{{ $t('label.cancel') }}</VueButton>
                <button v-if="isEdit" class="button is-danger is-light" @click="remove"><LucideTrash2 class="mr-1" />{{ $t('label.delete') }}</button>
            </div>
        </div>
    </div>
</template>
