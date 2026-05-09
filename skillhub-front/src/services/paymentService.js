import api from './axiosConfig';

/*
 * Service de paiement (simulé) pour l'inscription à une formation payante.
 *
 * Flow :
 *   1. L'apprenant clique "Suivre" sur une formation avec prix > 0
 *   2. Le frontend ouvre ModalPaiement (formulaire carte mock + champ password)
 *   3. À la soumission, on appelle paymentService.confirmer({formationId, motDePasse})
 *   4. Le backend vérifie le mot de passe (re-auth), recalcule le montant
 *      depuis la BDD (anti price-tampering), crée le Payment + Inscription.
 *
 * IMPORTANT : on n'envoie JAMAIS le numéro de carte, CVV ni date d'expiration
 * au backend. Le formulaire de carte est purement visuel pour la démo.
 * Seul le mot de passe et l'id formation sont envoyés.
 */
const paymentService = {
    /**
     * Confirme un paiement et déclenche l'inscription côté backend.
     *
     * @param {object} params
     * @param {number} params.formationId ID de la formation à acheter
     * @param {string} params.motDePasse Mot de passe re-saisi par l'apprenant
     * @returns {Promise<object>} { message, payment, inscription, montant_paye }
     * @throws AxiosError 401 si mot de passe incorrect, 400/403/404 selon le cas
     */
    async confirmer({ formationId, motDePasse }) {
        const response = await api.post('/payments/confirmer', {
            formation_id: formationId,
            mot_de_passe: motDePasse,
        });
        return response.data;
    },
};

export default paymentService;
