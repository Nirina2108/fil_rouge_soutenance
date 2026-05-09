/*
 * Configuration Vitest — runner de tests du frontend SkillHub.
 *
 * Vitest = équivalent Jest pour Vite : utilise la même chaîne de transform
 * que Vite (esbuild), donc démarrage très rapide et support natif ESM/JSX.
 *
 * Stratégie de test :
 *   - Périmètre testé limité aux services API (src/services), c'est là
 *     qu'on a le plus de logique métier (auth flow, parsing erreurs, etc.).
 *   - Les composants/pages ne sont PAS testés ici : couverture front
 *     atteinte via les services (mocks d'axios) plus les tests Feature backend.
 *   - Threshold 96 % strict : un test manquant fait échouer la CI.
 */

import { defineConfig } from "vitest/config";

export default defineConfig({
    test: {
        // Environnement DOM simulé (jsdom) — nécessaire pour localStorage,
        // window, document utilisés par axios + composants React.
        environment: "jsdom",

        // Active les globals (describe, it, expect) sans avoir à les importer.
        globals: true,

        // Fichier exécuté avant CHAQUE fichier de test (cf. src/tests/setup.js).
        setupFiles: ["./src/tests/setup.js"],

        // Périmètre des tests : uniquement les *.test.js des services API.
        include: ["src/services/**/*.test.js"],

        // Configuration du coverage (v8 = engine natif Node, plus rapide qu'Istanbul).
        coverage: {
            provider: "v8",
            // 3 formats : terminal lisible, HTML navigable, JSON pour Sonar.
            reporter: ["text", "html", "json-summary"],
            // On ne mesure que le code des services (le reste est testé via E2E).
            include: ["src/services/**/*.js"],
            // Thresholds CI : si une de ces métriques tombe sous 96 %, build échoue.
            thresholds: {
                lines: 96,
                functions: 96,
                statements: 96,
            },
        },
    },
});
