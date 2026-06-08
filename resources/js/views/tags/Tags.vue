<script setup>
    import TagBadge from '@/components/TagBadge.vue'
    import tagService from '@/services/tagService'
    import { useNotify } from '@2fauth/ui'

    const { t } = useI18n()
    const notify = useNotify()

    const tags       = ref([])
    const isLoading  = ref(false)
    const newName    = ref('')
    const newColor   = ref('#3273dc')
    const editingTag = ref(null)

    onMounted(fetchTags)

    async function fetchTags() {
        isLoading.value = true
        try {
            const { data } = await tagService.getAll()
            tags.value = data
        } catch {
            notify.alert({ text: t('error.data_cannot_be_refreshed_from_server') })
        } finally {
            isLoading.value = false
        }
    }

    async function createTag() {
        if (!newName.value.trim()) return
        try {
            const { data } = await tagService.create({ name: newName.value.trim(), color: newColor.value })
            tags.value.push(data)
            newName.value  = ''
            newColor.value = '#3273dc'
            notify.success({ text: t('notification.tag_created') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }

    function startEdit(tag) {
        editingTag.value = { ...tag }
    }

    async function saveEdit() {
        if (!editingTag.value) return
        try {
            const { data } = await tagService.update(editingTag.value.id, { name: editingTag.value.name, color: editingTag.value.color })
            const idx = tags.value.findIndex(t => t.id === data.id)
            if (idx !== -1) tags.value[idx] = data
            editingTag.value = null
            notify.success({ text: t('notification.tag_updated') })
        } catch (e) {
            notify.alert({ text: e.response?.data?.message ?? t('error.unknown') })
        }
    }

    async function deleteTag(tag) {
        if (!confirm(t('confirmation.delete_tag', { name: tag.name }))) return
        try {
            await tagService.delete(tag.id)
            tags.value = tags.value.filter(t => t.id !== tag.id)
            notify.success({ text: t('notification.tag_deleted') })
        } catch {
            notify.alert({ text: t('error.unknown') })
        }
    }
</script>

<template>
    <div class="container py-5">
        <h2 class="title is-3 mb-4">{{ $t('title.tags') }}</h2>

        <!-- Create tag form -->
        <div class="box mb-4">
            <h4 class="title is-5">{{ $t('label.create_tag') }}</h4>
            <div class="field has-addons">
                <div class="control">
                    <input type="color" class="input" v-model="newColor" style="width:3rem;padding:2px" />
                </div>
                <div class="control is-expanded">
                    <input class="input" type="text" v-model="newName" :placeholder="$t('field.tag_name')" @keydown.enter="createTag" />
                </div>
                <div class="control">
                    <VueButton class="button is-primary" @click="createTag">{{ $t('label.create') }}</VueButton>
                </div>
            </div>
        </div>

        <!-- Tags list -->
        <div v-if="isLoading" class="has-text-centered py-4">
            <span class="loader"></span>
        </div>
        <div v-else class="box">
            <p v-if="tags.length === 0" class="has-text-grey">{{ $t('message.no_tags_yet') }}</p>
            <table v-else class="table is-fullwidth is-hoverable">
                <thead>
                    <tr>
                        <th>{{ $t('label.tag') }}</th>
                        <th>{{ $t('label.accounts_count') }}</th>
                        <th>{{ $t('label.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="tag in tags" :key="tag.id">
                        <td>
                            <template v-if="editingTag?.id === tag.id">
                                <div class="field has-addons mb-0">
                                    <div class="control">
                                        <input type="color" class="input" v-model="editingTag.color" style="width:2.5rem;padding:2px" />
                                    </div>
                                    <div class="control is-expanded">
                                        <input class="input is-small" type="text" v-model="editingTag.name" @keydown.enter="saveEdit" />
                                    </div>
                                </div>
                            </template>
                            <TagBadge v-else :tag="tag" />
                        </td>
                        <td>{{ tag.accounts_count ?? 0 }}</td>
                        <td>
                            <div class="buttons are-small">
                                <template v-if="editingTag?.id === tag.id">
                                    <VueButton class="button is-success" @click="saveEdit">{{ $t('label.save') }}</VueButton>
                                    <VueButton class="button" @click="editingTag = null">{{ $t('label.cancel') }}</VueButton>
                                </template>
                                <template v-else>
                                    <VueButton class="button is-info is-light" @click="startEdit(tag)">{{ $t('label.edit') }}</VueButton>
                                    <VueButton class="button is-danger is-light" @click="deleteTag(tag)">{{ $t('label.delete') }}</VueButton>
                                </template>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
