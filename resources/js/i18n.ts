import { createI18n } from 'vue-i18n'

import type schema from '../lang/en.json'
import messages from '@intlify/unplugin-vue-i18n/messages'

export type I18nSchema = typeof schema
export type I18nLocales = 'ar' | 'bg' | 'ca' | 'da' | 'de' | 'en' | 'es-ES' | 'fr' | 'hi' | 'id' | 'it' | 'ja' | 'ko' | 'nl' | 'pl' | 'pt-BR' | 'pt-PT' | 'ru' | 'tr' | 'uk' | 'zh-CN' | 'zh-TW'

export default createI18n<[I18nSchema], I18nLocales>({
    legacy: false,
    locale: document.documentElement.lang,
    fallbackLocale: 'en',
    globalInjection: true,
    messages: messages as any,
})