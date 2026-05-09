import api from './axiosConfig';

/*
 * Service d'authentification : centralise tous les appels API liés à
 * l'utilisateur (register, login, profil, logout, photo) et la persistance
 * locale de la session (token JWT + objet utilisateur dans localStorage).
 *
 * Toutes les méthodes qui retournent un user sauvegardent automatiquement
 * la session pour que l'AuthContext puisse retrouver l'état au refresh.
 */

/**
 * Normalise l'utilisateur reçu du backend.
 *
 * Le backend Laravel renvoie le champ "nom" en français, mais quelques
 * composants front lisent "name" (héritage). On expose les deux clés pour
 * éviter les bugs d'affichage selon le composant qui consomme la donnée.
 */
function normaliserUtilisateur(user) {
    if (!user) {
        return null;
    }

    return {
        ...user,
        nom: user.nom || user.name || '',
        name: user.name || user.nom || '',
    };
}

const authService = {
    /**
     * Inscrit un nouvel utilisateur et le connecte directement (le backend
     * renvoie un token JWT dès la création).
     *
     * @param {string} nom Nom complet
     * @param {string} email Email unique
     * @param {string} password Mot de passe (min 6 caractères côté backend)
     * @param {string} passwordConfirmation Doit être identique au password
     * @param {string} role 'apprenant' ou 'formateur'
     * @returns {Promise<object>} Données de réponse normalisées (user + token)
     */
    async register(nom, email, password, passwordConfirmation, role) {
        const payload = {
            nom,
            email,
            password,
            // Le backend Laravel attend la clé snake_case password_confirmation pour la règle "confirmed".
            password_confirmation: passwordConfirmation,
            role
        };

        const response = await api.post('/register', payload);

        const utilisateurNormalise = normaliserUtilisateur(response.data.user);

        // Persistance de la session : le token sera renvoyé par axiosConfig sur les prochains appels.
        if (response.data.token) {
            localStorage.setItem('token', response.data.token);
        }

        if (utilisateurNormalise) {
            localStorage.setItem('utilisateur', JSON.stringify(utilisateurNormalise));
        }

        return {
            ...response.data,
            user: utilisateurNormalise
        };
    },

    /**
     * Connecte un utilisateur existant via email + mot de passe.
     *
     * @returns {Promise<object>} { token, user, message }
     */
    async login(email, password) {
        const payload = {
            email,
            password
        };

        const response = await api.post('/login', payload);

        const utilisateurNormalise = normaliserUtilisateur(response.data.user);

        if (response.data.token) {
            localStorage.setItem('token', response.data.token);
        }

        if (utilisateurNormalise) {
            localStorage.setItem('utilisateur', JSON.stringify(utilisateurNormalise));
        }

        return {
            ...response.data,
            user: utilisateurNormalise
        };
    },

    /**
     * Récupère le profil de l'utilisateur authentifié et met à jour le
     * cache local. Utile après un upload de photo, ou pour rafraîchir
     * les infos après une modification côté serveur.
     */
    async profile() {
        const response = await api.get('/profile');

        const utilisateurNormalise = normaliserUtilisateur(response.data.user);

        if (utilisateurNormalise) {
            localStorage.setItem('utilisateur', JSON.stringify(utilisateurNormalise));
        }

        return {
            ...response.data,
            user: utilisateurNormalise
        };
    },

    /**
     * Déconnecte l'utilisateur côté serveur (invalidation JWT) et purge
     * le localStorage côté client.
     */
    async logout() {
        const response = await api.post('/logout', {});

        localStorage.removeItem('token');
        localStorage.removeItem('utilisateur');

        return response.data;
    },

    /**
     * Upload une photo de profil au serveur (multipart).
     *
     * @param {File} fichier Fichier image (jpg/png/gif, max 2 MB côté backend)
     */
    async uploadPhoto(fichier) {
        const formData = new FormData();
        formData.append('photo', fichier);

        // Note : on fixe explicitement multipart/form-data ici, axios ajoute le boundary correctement
        // car il s'agit du seul champ et le navigateur a déjà rempli le FormData.
        const response = await api.post('/profil/photo', formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        });

        const utilisateurNormalise = normaliserUtilisateur(response.data.user);

        // Le user a maintenant le nouveau chemin photo_profil ; on remplace le cache local.
        if (utilisateurNormalise) {
            localStorage.setItem('utilisateur', JSON.stringify(utilisateurNormalise));
        }

        return {
            ...response.data,
            user: utilisateurNormalise
        };
    },

    /**
     * Lit l'utilisateur depuis localStorage (sans appel réseau).
     * Utilisé par l'AuthContext au démarrage pour retrouver la session.
     */
    obtenirUtilisateur() {
        const utilisateur = localStorage.getItem('utilisateur');

        if (!utilisateur) {
            return null;
        }

        try {
            return JSON.parse(utilisateur);
        } catch {
            // Donnée corrompue dans localStorage, on retourne null pour forcer une reconnexion propre.
            // catch sans paramètre : ESLint rule "no-unused-vars" satisfaite.
            return null;
        }
    },

    /**
     * Lit le token JWT depuis localStorage.
     */
    obtenirToken() {
        return localStorage.getItem('token');
    },

    /**
     * Indique si un token existe (proxy léger pour les composants qui veulent
     * juste tester la présence d'une session sans charger le user complet).
     */
    estConnecte() {
        return !!localStorage.getItem('token');
    },

    /**
     * Purge la session locale (utilisé en fallback si logout API échoue).
     */
    clear() {
        localStorage.removeItem('token');
        localStorage.removeItem('utilisateur');
    }
};

export default authService;
