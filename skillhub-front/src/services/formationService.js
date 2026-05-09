import api from './axiosConfig';

/*
 * Service de gestion des formations : encapsule tous les appels API liés
 * aux formations (CRUD, listage, recherche, upload PDF, téléchargement).
 *
 * Particularités :
 *  - creerFormation et modifierFormation envoient en multipart/form-data
 *    (FormData) car ils peuvent inclure un fichier PDF du cours.
 *  - telechargerPdf et ouvrirPdf utilisent responseType blob pour gérer
 *    le téléchargement binaire et créer une URL temporaire côté client.
 */

/**
 * Convertit un objet plain JS en FormData en filtrant les valeurs null/undefined.
 *
 * Sert à éviter d'envoyer des champs vides au backend (ce qui pourrait
 * écraser des valeurs existantes lors d'une modification).
 */
function construireFormData(data) {
    const formData = new FormData();
    Object.entries(data).forEach(([cle, valeur]) => {
        if (valeur === null || valeur === undefined) return;
        formData.append(cle, valeur);
    });
    return formData;
}

const formationService = {

    /**
     * Liste toutes les formations publiques avec filtres optionnels.
     * GET /formations?recherche=...&categorie=...&niveau=...
     *
     * Endpoint public, pas besoin d'être authentifié.
     */
    recupererFormations: async (filtres = {}) => {
        const response = await api.get('/formations', { params: filtres });
        return response.data;
    },

    /**
     * Récupère le détail d'une formation par son id.
     * GET /formations/:id
     *
     * Effet de bord : incrémente le compteur de vues côté serveur (logique
     * de comptage unique gérée dans FormationController::show).
     */
    recupererFormation: async (id) => {
        const response = await api.get(`/formations/${id}`);
        return response.data;
    },

    /**
     * Créer une nouvelle formation (formateur uniquement).
     * POST /formations — multipart pour upload PDF optionnel.
     * Content-Type undefined laisse le browser fixer multipart/form-data avec boundary.
     */
    creerFormation: async (data) => {
        const formData = construireFormData(data);
        const response = await api.post('/formations', formData, {
            headers: { 'Content-Type': undefined },
        });
        return response.data;
    },

    /**
     * Modifier une formation existante (formateur propriétaire uniquement).
     * Méthode spoofing : POST + _method=PUT pour permettre multipart.
     */
    modifierFormation: async (id, data) => {
        const formData = construireFormData(data);
        formData.append('_method', 'PUT');
        const response = await api.post(`/formations/${id}`, formData, {
            headers: { 'Content-Type': undefined },
        });
        return response.data;
    },

    /**
     * Télécharge le PDF du cours sur le poste de l'utilisateur.
     * GET /formations/:id/pdf
     * Accès : formateur propriétaire OU apprenant inscrit (filtre côté backend).
     *
     * Technique : on récupère un Blob, on crée une URL temporaire, on simule
     * un clic sur un lien <a download> caché, puis on libère l'URL.
     */
    telechargerPdf: async (id, titre = 'cours') => {
        // responseType blob est indispensable pour que axios ne tente pas de parser le binaire en JSON.
        const response = await api.get(`/formations/${id}/pdf`, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
        const lien = document.createElement('a');
        lien.href = url;
        // Sanitize le titre pour éviter les caractères invalides dans le nom de fichier OS.
        lien.download = `${titre.replace(/[^A-Za-z0-9_-]/g, '_')}.pdf`;
        document.body.appendChild(lien);
        lien.click();
        lien.remove();
        // Libération immédiate de l'URL pour éviter une fuite mémoire.
        window.URL.revokeObjectURL(url);
    },

    /**
     * Ouvre le PDF dans un nouvel onglet (lecture inline, sans téléchargement).
     *
     * window.open ouvre le blob URL dans une nouvelle fenêtre. Le navigateur
     * affiche le PDF dans son lecteur intégré. L'URL ne peut pas être révoquée
     * immédiatement (la nouvelle fenêtre a besoin du temps de la lire),
     * d'où le setTimeout de 60s qui laisse une marge confortable.
     */
    ouvrirPdf: async (id) => {
        const response = await api.get(`/formations/${id}/pdf`, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
        window.open(url, '_blank');
        setTimeout(() => window.URL.revokeObjectURL(url), 60_000);
    },

    /**
     * Supprime une formation (formateur propriétaire uniquement).
     * DELETE /formations/:id
     *
     * Cascade côté backend : modules, inscriptions, vues et fichier PDF associés
     * sont également supprimés.
     */
    supprimerFormation: async (id) => {
        const response = await api.delete(`/formations/${id}`);
        return response.data;
    },

    /**
     * Liste les formations créées par le formateur connecté.
     * GET /formateur/mes-formations
     *
     * Inclut les compteurs (inscriptions, vues) pour le dashboard formateur.
     */
    recupererMesFormations: async () => {
        const response = await api.get('/formateur/mes-formations');
        return response.data;
    },
};

export default formationService;