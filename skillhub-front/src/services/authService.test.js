// Imports des fonctions et helpers de Vitest (framework de test moderne pour Vite).
// describe : groupe de tests | expect : assertions | it : un test individuel
// vi : utilitaires de mock (équivalent jest) | beforeEach : hook avant chaque test.
import { describe, expect, it, vi, beforeEach } from "vitest";

// vi.hoisted : déclare un mock AVANT les imports pour pouvoir le réutiliser dans
// vi.mock plus bas. Permet d'inspecter les appels (toHaveBeenCalledWith).
const { apiMock } = vi.hoisted(() => ({
    apiMock: {
        post: vi.fn(),  // mock de api.post (retourne ce qu'on lui dit avec mockResolvedValue)
        get: vi.fn(),   // mock de api.get
    },
}));

// Remplace l'import "./axiosConfig" par notre mock pour tous les tests de ce fichier.
// authService.js importe ./axiosConfig — il recevra apiMock à la place du vrai axios.
vi.mock("./axiosConfig", () => ({
    default: apiMock,  // export default
}));

// Import du service à tester. Doit être APRÈS vi.mock pour que le mock soit pris en compte.
import authService from "./authService";

// Bloc principal de tests pour authService.
describe("authService", () => {
    // Hook : reset les mocks et localStorage avant chaque "it" pour avoir un état propre.
    beforeEach(() => {
        apiMock.post.mockReset();   // efface l'historique des appels et la valeur retournée
        apiMock.get.mockReset();
        localStorage.clear();       // vide le stockage local simulé par jsdom
    });

    // Test 1 : register normalise les données reçues et stocke le token + utilisateur.
    it("normalise et stocke l'utilisateur lors du register", async () => {
        // On configure le mock : api.post résoudra avec ce data.
        apiMock.post.mockResolvedValue({
            data: {
                token: "jwt-token",
                user: { nom: "Alice" },  // backend renvoie "nom" (français)
            },
        });

        // Appel réel de authService.register avec des paramètres factices.
        const result = await authService.register("Alice", "a@test.com", "pwd", "pwd", "apprenant");

        // Vérifie que le service a bien envoyé le bon payload au backend.
        expect(apiMock.post).toHaveBeenCalledWith("/register", {
            nom: "Alice",
            email: "a@test.com",
            password: "pwd",
            password_confirmation: "pwd",  // snake_case attendu par Laravel (règle "confirmed")
            role: "apprenant",
        });
        // La normalisation expose à la fois "nom" et "name" (compat).
        expect(result.user.name).toBe("Alice");
        // Le token est persisté dans localStorage pour les requêtes suivantes.
        expect(localStorage.getItem("token")).toBe("jwt-token");
    });

    // Test 2 : si le backend ne renvoie ni token ni user, on n'écrit rien en local.
    it("register n'ecrit rien en local sans token ni user", async () => {
        apiMock.post.mockResolvedValue({ data: {} });  // réponse vide

        const result = await authService.register("Alice", "a@test.com", "pwd", "pwd", "apprenant");

        expect(result.user).toBeNull();                          // user normalisé = null
        expect(localStorage.getItem("token")).toBeNull();         // pas de token stocké
        expect(localStorage.getItem("utilisateur")).toBeNull();   // pas d'utilisateur stocké
    });

    // Test 3 : login avec un user qui a "name" (anglais) — le service doit ajouter "nom".
    it("normalise avec fallback name lors du login", async () => {
        apiMock.post.mockResolvedValue({
            data: {
                token: "jwt-login",
                user: { name: "Bob" },  // backend renvoie "name" cette fois
            },
        });

        const result = await authService.login("b@test.com", "pwd");

        // Vérification du payload envoyé.
        expect(apiMock.post).toHaveBeenCalledWith("/login", {
            email: "b@test.com",
            password: "pwd",
        });
        // La normalisation a ajouté "nom" pour compatibilité avec les composants français.
        expect(result.user.nom).toBe("Bob");
    });

    // Test 4 : profile() appelle GET /profile et met à jour le user local.
    it("met a jour le profil local", async () => {
        apiMock.get.mockResolvedValue({
            data: { user: { name: "Charlie" } },
        });

        const result = await authService.profile();

        expect(apiMock.get).toHaveBeenCalledWith("/profile");
        expect(result.user.nom).toBe("Charlie");  // normalisation appliquée
    });

    // Test 5 : profile() avec backend qui ne renvoie pas d'utilisateur.
    it("profile retourne user null si backend ne renvoie pas d'utilisateur", async () => {
        apiMock.get.mockResolvedValue({ data: {} });

        const result = await authService.profile();

        expect(result.user).toBeNull();
    });

    // Test 6 : logout supprime le token + utilisateur du localStorage.
    it("supprime la session locale lors du logout", async () => {
        // On simule un utilisateur déjà connecté.
        localStorage.setItem("token", "x");
        localStorage.setItem("utilisateur", "{}");
        apiMock.post.mockResolvedValue({ data: { message: "ok" } });

        await authService.logout();

        // Le service appelle POST /logout avec un body vide.
        expect(apiMock.post).toHaveBeenCalledWith("/logout", {});
        // Le localStorage est vidé après logout.
        expect(localStorage.getItem("token")).toBeNull();
        expect(localStorage.getItem("utilisateur")).toBeNull();
    });

    // Test 7 : uploadPhoto envoie bien un FormData multipart (pas du JSON).
    it("envoie un FormData pour uploadPhoto", async () => {
        // Création d'un faux File pour simuler un upload.
        const fakeFile = new File(["a"], "photo.jpg", { type: "image/jpeg" });
        apiMock.post.mockResolvedValue({ data: { user: { nom: "Dana" } } });

        const result = await authService.uploadPhoto(fakeFile);

        // Vérifie que le 2e argument est UN FormData (instance) et le 3e les headers multipart.
        expect(apiMock.post).toHaveBeenCalledWith(
            "/profil/photo",
            expect.any(FormData),  // matcher : "n'importe quelle instance de FormData"
            { headers: { "Content-Type": "multipart/form-data" } }
        );
        expect(result.user.name).toBe("Dana");
    });

    // Test 8 : obtenirUtilisateur retourne null si rien en localStorage ou si JSON invalide.
    it("retourne null si utilisateur local absent ou invalide", () => {
        expect(authService.obtenirUtilisateur()).toBeNull();  // localStorage vide

        localStorage.setItem("utilisateur", "not-json");  // chaîne corrompue
        expect(authService.obtenirUtilisateur()).toBeNull();  // try/catch dans le service capture
    });

    // Test 9 : obtenirUtilisateur parse correctement le JSON stocké.
    it("retourne l'utilisateur local si le JSON est valide", () => {
        localStorage.setItem("utilisateur", JSON.stringify({ nom: "Eva" }));
        expect(authService.obtenirUtilisateur()).toEqual({ nom: "Eva" });
    });

    // Test 10 : obtenirJeton et estConnecte fonctionnent en sync avec localStorage.
    it("retourne token et etat de connexion", () => {
        expect(authService.estConnecte()).toBe(false);  // pas de token initial

        localStorage.setItem("token", "abc");
        expect(authService.obtenirJeton()).toBe("abc");      // lit le token
        expect(authService.estConnecte()).toBe(true);    // session active
    });

    // Test 11 : clear() vide manuellement la session locale (sans appel API).
    it("clear vide le stockage", () => {
        localStorage.setItem("token", "abc");
        localStorage.setItem("utilisateur", "{}");

        authService.clear();

        expect(localStorage.getItem("token")).toBeNull();
        expect(localStorage.getItem("utilisateur")).toBeNull();
    });
});
