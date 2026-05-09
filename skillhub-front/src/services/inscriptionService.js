import api from './axiosConfig';

/*
 * Service de gestion des inscriptions des apprenants aux formations.
 *
 * Une inscription représente le lien entre un apprenant et une formation
 * qu'il suit, avec son pourcentage de progression. Ces endpoints sont
 * tous réservés au rôle apprenant côté backend.
 */
const inscriptionService = {
    /**
     * Inscrit l'apprenant connecté à une formation.
     * POST /formations/:id/inscription
     *
     * Le backend renvoie 409 si l'apprenant est déjà inscrit (gestion
     * d'error à faire côté composant qui appelle).
     */
    sInscrire: async (formationId) => {
        const response = await api.post(`/formations/${formationId}/inscription`);
        return response.data;
    },

    /**
     * Désinscrit l'apprenant connecté d'une formation.
     * DELETE /formations/:id/inscription
     *
     * Attention : la progression est perdue (suppression dure côté serveur).
     */
    seDesinscrire: async (formationId) => {
        const response = await api.delete(`/formations/${formationId}/inscription`);
        return response.data;
    },

    /**
     * Récupère les formations suivies par l'apprenant connecté avec leur progression.
     * GET /apprenant/formations
     *
     * Le backend renvoie un objet { message, inscriptions: [...] }. On unwrappe
     * pour retourner directement le tableau (plus pratique pour les composants).
     */
    mesFormations: async () => {
        const response = await api.get('/apprenant/formations');
        return response.data.inscriptions || [];
    },
};

export default inscriptionService;
