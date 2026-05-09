/*
 * Configuration ESLint — linter JavaScript/JSX du frontend SkillHub.
 *
 * Format "flat config" (ESLint 9+) qui remplace l'ancien .eslintrc.json.
 * Lance avec `npm run lint`. Échoue la CI s'il y a au moins une erreur.
 *
 * Empile 3 préréglages :
 *   - js.configs.recommended       : règles JavaScript de base
 *   - reactHooks                    : vérifie les Rules of Hooks (deps, conditions)
 *   - reactRefresh                  : compatibilité avec le HMR Vite (export par défaut)
 */

import js from '@eslint/js'
import globals from 'globals'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import { defineConfig, globalIgnores } from 'eslint/config'

export default defineConfig([
  // Le bundle Vite généré dans dist/ ne doit pas être linté.
  globalIgnores(['dist']),

  {
    // Cible : tous les fichiers source JS et JSX.
    files: ['**/*.{js,jsx}'],

    // Hérite des préréglages dans cet ordre (les suivants peuvent écraser les précédents).
    extends: [
      js.configs.recommended,
      reactHooks.configs.flat.recommended,
      reactRefresh.configs.vite,
    ],

    // Options du parseur JS.
    languageOptions: {
      // ES2020 : optional chaining, nullish coalescing, etc.
      ecmaVersion: 2020,
      // Globals navigateur (window, document, fetch, ...).
      globals: globals.browser,
      parserOptions: {
        // Permet d'utiliser les features JS les plus récentes.
        ecmaVersion: 'latest',
        // Active le support JSX.
        ecmaFeatures: { jsx: true },
        // Modules ESM (import/export).
        sourceType: 'module',
      },
    },

    // Règles personnalisées.
    rules: {
      // Erreur sur variables non utilisées, sauf si nom commence par majuscule
      // ou underscore (composants importés non utilisés directement, vars de déstructuration ignorées).
      'no-unused-vars': ['error', { varsIgnorePattern: '^[A-Z_]' }],
    },
  },
])
