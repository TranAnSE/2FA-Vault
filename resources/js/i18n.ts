import { createI18n } from 'vue-i18n'
import { watch } from 'vue'

import type schema from '../lang/en.json'
import messages from '@intlify/unplugin-vue-i18n/messages'
import { sharedI18n } from '@2fauth/ui'

export type I18nSchema = typeof schema
export type I18nLocales = 'ar' | 'bg' | 'ca' | 'da' | 'de' | 'en' | 'es-ES' | 'fr' | 'hi' | 'id' | 'it' | 'ja' | 'ko' | 'nl' | 'pl' | 'pt-BR' | 'pt-PT' | 'ru' | 'tr' | 'uk' | 'zh-CN' | 'zh-TW'

const i18n = createI18n<[I18nSchema], I18nLocales>({
    legacy: false,
    locale: document.documentElement.lang,
    fallbackLocale: 'en',
    globalInjection: true,
    messages: messages as any,
})

// Shared @2fauth/ui components translate through their own i18n instance,
// which ships without messages. Feed it the app catalog and keep its locale
// in sync so ui-package components render translated labels.
for (const [locale, catalog] of Object.entries(messages as Record<string, Record<string, unknown>>)) {
    sharedI18n.global.mergeLocaleMessage(locale, catalog)
}
watch(i18n.global.locale, (locale) => {
    sharedI18n.global.locale.value = locale as I18nLocales
})

export default i18n
