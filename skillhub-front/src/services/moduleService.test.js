// Imports Vitest standard.
import { describe, expect, it, vi, beforeEach } from "vitest";

// Mock api : 4 méthodes utilisées par moduleService (get/post/put/delete).
const { apiMock } = vi.hoisted(() => ({
    apiMock: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

// Remplace ./axiosConfig.
vi.mock("./axiosConfig", () => ({
    default: apiMock,
}));

import moduleService from "./moduleService";

describe("moduleService", () => {
    // Reset avant chaque test.
    beforeEach(() => {
        apiMock.get.mockReset();
        apiMock.post.mockReset();
        apiMock.put.mockReset();
        apiMock.delete.mockReset();
    });

    // Test 1 : recupererModules construit l'URL avec l'id de la formation parente.
    it("recupererModules", async () => {
        apiMock.get.mockResolvedValue({ data: [] });
        await moduleService.recupererModules(10);
        expect(apiMock.get).toHaveBeenCalledWith("/formations/10/modules");
    });

    // Test 2 : creerModule POST avec body JSON sur l'URL de la formation.
    it("creerModule", async () => {
        apiMock.post.mockResolvedValue({ data: {} });
        await moduleService.creerModule(12, { titre: "M1" });
        // Le body est passé tel quel en JSON (pas de FormData ici).
        expect(apiMock.post).toHaveBeenCalledWith("/formations/12/modules", { titre: "M1" });
    });

    // Test 3 : modifierModule utilise PUT direct (pas de spoofing) car body en JSON.
    it("modifierModule", async () => {
        apiMock.put.mockResolvedValue({ data: {} });
        await moduleService.modifierModule(2, { titre: "M2" });
        // PUT /modules/{id} (sans le préfixe formation).
        expect(apiMock.put).toHaveBeenCalledWith("/modules/2", { titre: "M2" });
    });

    // Test 4 : supprimerModule en DELETE.
    it("supprimerModule", async () => {
        apiMock.delete.mockResolvedValue({ data: {} });
        await moduleService.supprimerModule(2);
        expect(apiMock.delete).toHaveBeenCalledWith("/modules/2");
    });

    // Test 5 : terminerModule envoie un POST sur l'endpoint dédié (sans body).
    it("terminerModule", async () => {
        apiMock.post.mockResolvedValue({ data: {} });
        await moduleService.terminerModule(3);
        expect(apiMock.post).toHaveBeenCalledWith("/modules/3/terminer");
    });

    // Test 6 : recupererMesModulesTermines récupère la liste des IDs déjà terminés par l'apprenant.
    it("recupererMesModulesTermines", async () => {
        apiMock.get.mockResolvedValue({ data: {} });
        await moduleService.recupererMesModulesTermines(14);
        expect(apiMock.get).toHaveBeenCalledWith("/formations/14/modules-termines");
    });
});
