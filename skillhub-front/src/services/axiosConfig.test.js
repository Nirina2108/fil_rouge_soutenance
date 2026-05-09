// Imports Vitest standard pour faire des tests unitaires.
import { beforeEach, describe, expect, it, vi } from "vitest";

// vi.hoisted : exécuté avant les imports du module testé. Indispensable ici
// car axiosConfig.js appelle axios.create() AU CHARGEMENT, donc on doit avoir
// le mock prêt avant que la première ligne du fichier ne s'exécute.
const hoisted = vi.hoisted(() => {
    // On capture les fonctions intercepteurs passées par axiosConfig.js
    // pour pouvoir les appeler manuellement dans nos tests.
    const state = {
        requestOk: null,    // intercepteur de requête en succès
        requestErr: null,   // intercepteur de requête en error
        responseOk: null,   // intercepteur de réponse en succès
        responseErr: null,  // intercepteur de réponse en error (le plus important : gère le 401)
    };

    // Faux objet axios avec interceptors.request.use et interceptors.response.use.
    // Quand axiosConfig.js appelle .use(fn1, fn2), on les enregistre dans state.
    const apiMock = {
        interceptors: {
            request: {
                use: vi.fn((onFulfilled, onRejected) => {
                    state.requestOk = onFulfilled;
                    state.requestErr = onRejected;
                }),
            },
            response: {
                use: vi.fn((onFulfilled, onRejected) => {
                    state.responseOk = onFulfilled;
                    state.responseErr = onRejected;
                }),
            },
        },
    };

    return {
        state,
        apiMock,
        // Mock de axios.create qui retourne notre apiMock à la place d'une vraie instance.
        createMock: vi.fn(() => apiMock),
    };
});

// On remplace le module "axios" par un objet qui expose seulement create().
vi.mock("axios", () => ({
    default: {
        create: hoisted.createMock,
    },
}));

// Import du module testé. Au moment de cet import, axiosConfig.js exécute :
// const api = axios.create({...}) -> appelle hoisted.createMock -> renvoie apiMock
// puis api.interceptors.request.use(...) -> stocke dans state
import api from "./axiosConfig";

describe("axiosConfig", () => {
    // Reset localStorage avant chaque test pour garantir l'isolation.
    beforeEach(() => {
        localStorage.clear();
    });

    // Test 1 : axiosConfig.js a bien créé l'instance avec la bonne config.
    it("cree une instance axios avec la baseURL attendue", () => {
        // L'export "api" doit être l'objet retourné par axios.create (= apiMock).
        expect(api).toBe(hoisted.apiMock);
        // Vérifie les arguments passés à axios.create.
        expect(hoisted.createMock).toHaveBeenCalledWith({
            baseURL: "http://localhost:8001/api",
            timeout: 30000,
            headers: {
                "Content-Type": "application/json",
            },
        });
    });

    // Test 2 : l'intercepteur de requête ajoute Bearer si un token est en localStorage.
    it("ajoute Authorization quand un token est present", async () => {
        localStorage.setItem("token", "jwt-token");

        // On simule une requête sortante avec une config vide.
        const config = { headers: {} };
        // On appelle directement l'intercepteur capturé (state.requestOk).
        const result = await hoisted.state.requestOk(config);

        // L'intercepteur doit avoir ajouté l'en-tête Authorization.
        expect(result.headers.Authorization).toBe("Bearer jwt-token");
    });

    // Test 3 : sans token en localStorage, l'intercepteur ne touche pas les headers.
    it("laisse la requete intacte sans token", async () => {
        const config = { headers: {} };
        const result = await hoisted.state.requestOk(config);

        expect(result.headers.Authorization).toBeUndefined();
    });

    // Test 4 : l'error du request interceptor (rare) est propagée comme rejection.
    it("propage l'error du request interceptor", async () => {
        const error = new Error("request failed");
        // rejects.toBe vérifie que la promesse rejette avec l'error d'origine (pas wrapped).
        await expect(hoisted.state.requestErr(error)).rejects.toBe(error);
    });

    // Test 5 : intercepteur de réponse en succès = passthrough (renvoie la réponse telle quelle).
    it("retourne directement la response en succes", () => {
        const payload = { data: { ok: true } };
        expect(hoisted.state.responseOk(payload)).toBe(payload);
    });

    // Test 6 : sur 401 avec un token existant, on purge la session locale.
    // (le redirect via window.location ne peut pas être testé ici sans plus de mock)
    it("nettoie la session sur error 401", async () => {
        localStorage.setItem("token", "t");
        localStorage.setItem("utilisateur", "{}");

        // Erreur HTTP simulée que le backend Laravel renverrait sur token expiré.
        const error = { response: { status: 401, data: { message: "expired" } } };

        // L'intercepteur doit re-rejeter l'error (pour que le code appelant la voie).
        await expect(hoisted.state.responseErr(error)).rejects.toEqual(error);
        // Mais avant de rejeter, il a purgé localStorage.
        expect(localStorage.getItem("token")).toBeNull();
        expect(localStorage.getItem("utilisateur")).toBeNull();
    });

    // Test 7 : si pas de token, un 401 ne déclenche pas le reload (cas d'une route publique).
    it("ne redirige pas sur 401 quand aucun token n'existe", async () => {
        const error = { response: { status: 401 } };

        await expect(hoisted.state.responseErr(error)).rejects.toEqual(error);
        // localStorage reste vide (rien à purger).
        expect(localStorage.getItem("token")).toBeNull();
    });

    // Test 8 : sur une error autre que 401 (500, 404, ...), on ne touche PAS au localStorage.
    it("ne nettoie pas sur error hors 401", async () => {
        localStorage.setItem("token", "still-there");

        const error = { response: { status: 500 } };
        await expect(hoisted.state.responseErr(error)).rejects.toEqual(error);

        // Le token est toujours là après une error 500 (pas un problème d'auth).
        expect(localStorage.getItem("token")).toBe("still-there");
    });
});
