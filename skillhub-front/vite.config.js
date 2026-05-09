/*
 * Configuration Vite — bundler / dev server du frontend SkillHub.
 *
 * Vite est utilisé en dev (HMR, ESM natif, démarrage instantané) et au build
 * (Rollup en sous-couche pour générer le dossier dist/ minifié).
 * Doc officielle : https://vite.dev/config/
 */

import { defineConfig } from 'vite'
// Plugin officiel React (transforme JSX, gère le Fast Refresh).
import react from '@vitejs/plugin-react'

export default defineConfig({
  // Plugins activés — ici uniquement le plugin React.
  plugins: [react()],

  server: {
    // host '0.0.0.0' = écoute sur TOUTES les interfaces réseau.
    // Indispensable en Docker : sinon le serveur écoute uniquement sur le loopback
    // du conteneur et n'est pas joignable depuis l'hôte malgré le port mapping.
    host: '0.0.0.0',
    // Port HTTP exposé par Vite. Aligné avec docker-compose.yml (5173:5173).
    port: 5173,
  },
})
