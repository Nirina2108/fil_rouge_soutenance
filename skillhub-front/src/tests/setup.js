/*
 * Setup global de Vitest — chargé AVANT chaque fichier de test.
 *
 * Configuré dans vitest.config.js (clé `test.setupFiles`). Sert à mettre
 * en place un environnement propre et isolé entre les tests pour éviter
 * que l'état laissé par un test ne pollue le suivant.
 *
 * Comportements installés :
 *  - vide localStorage avant ET après chaque test (le code prod stocke
 *    le token JWT et l'utilisateur dans localStorage : sans nettoyage, un
 *    test qui s'authentifie laisserait le token visible aux suivants).
 *  - réinitialise tous les mocks Vitest (vi.fn / vi.spyOn) avant chaque test
 *    pour repartir d'une slate vierge sans interférence.
 */

import { afterEach, beforeEach, vi } from "vitest";

// Avant chaque test : on isole l'environnement (storage vide, mocks frais).
beforeEach(() => {
    // Vide les clés "token" et "utilisateur" qui pourraient subsister.
    localStorage.clear();
    // Annule tous les vi.fn / vi.spyOn créés dans les tests précédents.
    vi.restoreAllMocks();
});

// Après chaque test : on nettoie aussi pour ne pas contaminer un éventuel test suivant
// si un test laisse traîner du state même en cas d'erreur.
afterEach(() => {
    localStorage.clear();
});
