// Imports Vitest standard.
import { describe, expect, it, vi, beforeEach } from "vitest";

// Mock de api hoisté pour pouvoir l'inspecter dans les assertions.
// Cinq méthodes mockées car formationService utilise get/post/put/delete.
const { apiMock } = vi.hoisted(() => ({
    apiMock: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

// Remplace ./axiosConfig par notre mock pour isoler le service du vrai axios.
vi.mock("./axiosConfig", () => ({
    default: apiMock,
}));

// Import APRÈS le mock.
import formationService from "./formationService";

describe("formationService", () => {
    // Reset des mocks avant chaque test pour éviter les interférences.
    beforeEach(() => {
        apiMock.get.mockReset();
        apiMock.post.mockReset();
        apiMock.put.mockReset();
        apiMock.delete.mockReset();
    });

    // Test 1 : recupererFormations transmet bien les filtres en query params.
    it("recupererFormations transmet les filtres", async () => {
        apiMock.get.mockResolvedValue({ data: [{ id: 1 }] });
        const data = await formationService.recupererFormations({ niveau: "debutant" });

        // Le service doit avoir appelé GET /formations?niveau=debutant (params dans la config axios).
        expect(apiMock.get).toHaveBeenCalledWith("/formations", { params: { niveau: "debutant" } });
        // Et retourner directement response.data.
        expect(data).toEqual([{ id: 1 }]);
    });

    // Test 2 : recupererFormation construit l'URL avec l'id.
    it("recupererFormation recupere une formation", async () => {
        apiMock.get.mockResolvedValue({ data: { id: 2 } });
        await formationService.recupererFormation(2);
        expect(apiMock.get).toHaveBeenCalledWith("/formations/2");
    });

    // Test 3 : creerFormation envoie bien du multipart/form-data (pour permettre l'upload PDF).
    it("creerFormation poste les data en FormData", async () => {
        apiMock.post.mockResolvedValue({ data: { ok: true } });
        await formationService.creerFormation({ titre: "React" });

        // Une seule requête POST envoyée.
        expect(apiMock.post).toHaveBeenCalledTimes(1);
        // Récupère les arguments du 1er appel pour les inspecter individuellement.
        const [url, formData, config] = apiMock.post.mock.calls[0];
        expect(url).toBe("/formations");
        // Le 2e arg est un FormData (pas un objet JSON).
        expect(formData).toBeInstanceOf(FormData);
        // Le titre a été correctement appendu au FormData.
        expect(formData.get("titre")).toBe("React");
        // Content-Type undefined laisse le navigateur fixer multipart/form-data avec boundary.
        expect(config).toEqual({ headers: { "Content-Type": undefined } });
    });

    // Test 4 : modifierFormation utilise POST + _method=PUT (méthode spoofing Laravel).
    it("modifierFormation envoie un POST avec _method=PUT", async () => {
        apiMock.post.mockResolvedValue({ data: { ok: true } });
        await formationService.modifierFormation(3, { titre: "JS" });

        // C'est bien un POST qu'on appelle (pas un PUT) car PHP ne parse pas multipart sur les vrais PUT.
        expect(apiMock.post).toHaveBeenCalledTimes(1);
        const [url, formData, config] = apiMock.post.mock.calls[0];
        expect(url).toBe("/formations/3");
        expect(formData).toBeInstanceOf(FormData);
        expect(formData.get("titre")).toBe("JS");
        // Le champ _method=PUT est ce qui dit à Laravel : "traite-moi comme un PUT".
        expect(formData.get("_method")).toBe("PUT");
        expect(config).toEqual({ headers: { "Content-Type": undefined } });
    });

    // Test 5 : supprimerFormation appelle DELETE /formations/{id}.
    it("supprimerFormation envoie un delete", async () => {
        apiMock.delete.mockResolvedValue({ data: { ok: true } });
        await formationService.supprimerFormation(4);
        expect(apiMock.delete).toHaveBeenCalledWith("/formations/4");
    });

    // Test 6 : recupererMesFormations cible le bon endpoint protégé.
    it("recupererMesFormations appelle l endpoint protege", async () => {
        apiMock.get.mockResolvedValue({ data: [] });
        await formationService.recupererMesFormations();
        expect(apiMock.get).toHaveBeenCalledWith("/formateur/mes-formations");
    });
});
