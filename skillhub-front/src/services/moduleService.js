import api from './axiosConfig';

/*
 * Service de gestion des modules (leçons d'une formation).
 *
 * Côté formateur : CRUD pour gérer les modules de ses formations.
 * Côté apprenant : marquer un module comme terminé pour faire progresser
 * son taux d'avancement, et récupérer la liste des modules déjà finis
 * (utilisé pour pré-cocher les modules dans la page Apprendre).
 */
const moduleService = {
    /**
     * Liste tous les modules d'une formation, triés par ordre.
     * GET /formations/:id/modules — endpoint public.
     */
    async recupererModules(formationId) {
        const response = await api.get(`/formations/${formationId}/modules`);
        return response.data;
    },

    /**
     * Crée un nouveau module dans une formation.
     * POST /formations/:id/modules — réservé au formateur propriétaire.
     */
    async creerModule(formationId, data) {
        const response = await api.post(`/formations/${formationId}/modules`, data);
        return response.data;
    },

    /**
     * Modifie un module existant.
     * PUT /modules/:id — réservé au formateur propriétaire.
     */
    async modifierModule(id, data) {
        const response = await api.put(`/modules/${id}`, data);
        return response.data;
    },

    /**
     * Supprime un module.
     * DELETE /modules/:id — réservé au formateur propriétaire.
     */
    async supprimerModule(id) {
        const response = await api.delete(`/modules/${id}`);
        return response.data;
    },

    /**
     * Marque un module comme terminé pour l'apprenant connecté.
     * POST /modules/:id/terminer
     *
     * Le backend renvoie la nouvelle progression (en %) de l'apprenant
     * pour cette formation, calculée à partir du nombre de modules terminés.
     */
    async terminerModule(id) {
        const response = await api.post(`/modules/${id}/terminer`);
        return response.data;
    },

    /**
     * Récupère les IDs des modules déjà terminés par l'apprenant connecté
     * pour une formation donnée.
     * GET /formations/:id/modules-termines
     *
     * Sert à pré-cocher les modules finis dans le sidebar de la page Apprendre.
     */
    async recupererMesModulesTermines(formationId) {
        const response = await api.get(`/formations/${formationId}/modules-termines`);
        return response.data;
    },
};

export default moduleService;
