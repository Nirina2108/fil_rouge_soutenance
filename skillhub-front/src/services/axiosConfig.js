import axios from "axios";

/*
 * Client Axios mutualisé pour tous les appels au backend Laravel SkillHub.
 *
 * Trois responsabilités :
 *  1. Centraliser la base URL et les headers par défaut.
 *  2. Injecter automatiquement le token JWT dans chaque requête sortante.
 *  3. Détecter les réponses 401 (token expiré/invalide) et nettoyer la session.
 *
 * Toutes les classes de service (authService, formationService, etc.) importent
 * cette instance "api" pour profiter de ces comportements communs.
 */

const api = axios.create({
    // URL de base : toutes les routes Laravel sont préfixées par /api.
    baseURL: "http://localhost:8001/api",
    headers: {
        // Content-Type JSON par défaut. Surchargé en undefined pour les uploads multipart
        // (laisse le navigateur fixer multipart/form-data avec le bon boundary).
        "Content-Type": "application/json",
    },
});

/*
 * Intercepteur de requête : ajoute l'en-tête Authorization si un token JWT
 * est stocké en localStorage. Évite de devoir faire ce travail dans chaque service.
 */
api.interceptors.request.use(
    (config) => {
        const jeton = localStorage.getItem("token");

        if (jeton) {
            config.headers.Authorization = `Bearer ${jeton}`;
        }

        return config;
    },
    (erreur) => Promise.reject(erreur)
);

/*
 * Intercepteur de réponse : gestion centralisée des erreurs.
 *
 * Cas 401 (token absent/expiré/invalide) : on purge localStorage et on
 * recharge la page d'accueil. Cela force la resynchro de l'état React
 * avec la réalité (l'utilisateur n'est plus connecté).
 *
 * En développement, on logue les erreurs API en console pour debug.
 * En production, on reste silencieux pour ne pas exposer des données.
 */
api.interceptors.response.use(
    (reponse) => reponse,
    (erreur) => {
        const statut = erreur.response?.status;

        if (import.meta.env.DEV) {
            console.error("Erreur API Laravel :", statut, erreur.response?.data);
        }

        if (statut === 401) {
            // On ne déclenche le reload que si un token EXISTAIT (sinon c'est un 401 attendu sur une route publique).
            const jetonExistait = !!localStorage.getItem('token');
            if (jetonExistait) {
                localStorage.removeItem('token');
                localStorage.removeItem('utilisateur');
                // Redirection vers la racine pour remettre le contexte Auth à zéro.
                window.location.href = '/';
            }
        }

        return Promise.reject(erreur);
    }
);

export default api;
