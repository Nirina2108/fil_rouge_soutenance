import { createContext, useContext, useState } from "react";
import authService from "../services/authService";

/*
 * Contexte React global pour la session utilisateur.
 *
 * Fournit l'utilisateur courant, ses helpers de rôle, et les méthodes
 * login/register/logout utilisées partout dans l'app sans avoir à passer
 * les props manuellement de composant en composant (évite le "prop drilling").
 *
 * L'état est synchronisé avec localStorage via authService :
 *  - Au démarrage, on lit la session depuis localStorage si un token existe.
 *  - Après login/register, on met à jour localStorage ET l'état React.
 *  - Au logout, on purge les deux.
 */

const AuthContext = createContext(null);

/**
 * Provider à monter en racine de l'application (autour de <Routes>).
 * Tous les composants enfants peuvent accéder au contexte via useAuth().
 */
export function AuthProvider({ children }) {
    // État initial : on tente de restaurer la session depuis localStorage.
    // Si le token est absent mais l'utilisateur présent (état incohérent),
    // on purge l'utilisateur pour repartir d'une session propre.
    const [utilisateur, setUtilisateur] = useState(() => {
        const token = localStorage.getItem('token');
        if (!token) {
            localStorage.removeItem('utilisateur');
            return null;
        }
        return authService.obtenirUtilisateur();
    });

    /**
     * Connecte l'utilisateur via email + mot de passe.
     * Met à jour l'état React puis renvoie la réponse complète.
     */
    const login = async (email, password) => {
        const data = await authService.login(email, password);
        setUtilisateur(data.user);
        return data;
    };

    /**
     * Inscrit un nouvel utilisateur. Le backend renvoie un token JWT
     * que authService stocke automatiquement, on synchronise juste l'état React.
     */
    const register = async (nom, email, password, passwordConfirmation, role) => {
        const data = await authService.register(nom, email, password, passwordConfirmation, role);
        setUtilisateur(data.user);
        return data;
    };

    /**
     * Déconnecte l'utilisateur (côté serveur ET côté client).
     */
    const logout = async () => {
        await authService.logout();
        setUtilisateur(null);
    };

    /**
     * Helpers de vérification de rôle. Évitent la répétition de
     * `utilisateur?.role === "..."` un peu partout dans l'app.
     */
    const estConnecte = () => utilisateur !== null;

    const estFormateur = () =>
        utilisateur !== null && utilisateur.role === "formateur";

    const estApprenant = () =>
        utilisateur !== null && utilisateur.role === "apprenant";

    // Objet exposé par le contexte. setUtilisateur est exposé pour permettre
    // une mise à jour locale après uploadPhoto (sans repasser par login).
    const valeur = {
        utilisateur,
        setUtilisateur,
        login,
        register,
        logout,
        estConnecte,
        estFormateur,
        estApprenant
    };

    return (
        <AuthContext.Provider value={valeur}>
            {children}
        </AuthContext.Provider>
    );
}

/**
 * Hook utilitaire pour consommer le contexte d'authentification depuis n'importe
 * quel composant enfant du provider. Exemple : const { utilisateur, logout } = useAuth();
 */
export function useAuth() {
    return useContext(AuthContext);
}
