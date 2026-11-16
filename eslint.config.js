import js from '@eslint/js'
import eslintPluginVue from 'eslint-plugin-vue'
import globals from 'globals'
import autoImports from './.eslintrc-auto-import.mjs'
import { defineConfig } from "eslint/config";

// const compat = new FlatCompat()

export default defineConfig([
    // autoImports,
    js.configs.recommended,
    ...eslintPluginVue.configs['flat/essential'],
    {
        name: 'app/files-to-lint',
        files: ['resources/js/**/*.{js,mjs,jsx,vue}'],
        rules: {
            'vue/multi-word-component-names': 'off',
            'no-unused-vars': 'off',
        },
        languageOptions: {
            globals: {
                ...globals.node,
                ...globals.browser,
                ...autoImports.globals,
                // The auto-import scan registers services/crypto.js's default
                // export as a global named `crypto`, silently rewriting bare
                // `crypto` references into that object at build time. Bare
                // `crypto.subtle` (WebCrypto intended) then breaks at runtime.
                // Disabling the global makes every bare `crypto` fail no-undef
                // here — WebCrypto access must go through globalThis.crypto.
                crypto: 'off',
            },
        },
    },
])
