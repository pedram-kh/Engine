import { createI18n } from 'vue-i18n'

import enApp from './locales/en/app.json'
import ptApp from './locales/pt/app.json'
import itApp from './locales/it/app.json'

type MessageSchema = typeof enApp

const messages: Record<'en' | 'pt' | 'it', { app: MessageSchema['app'] }> = {
  en: { app: enApp.app },
  pt: { app: ptApp.app },
  it: { app: itApp.app },
}

export const i18n = createI18n<[MessageSchema], 'en' | 'pt' | 'it'>({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  availableLocales: ['en', 'pt', 'it'],
  messages,
})
