/*
 * Helper de formatage du prix d'une formation pour l'affichage sur les cartes.
 *
 * Règle d'affichage :
 *   - Prix null, 0 ou 0.00 → "Gratuit"
 *   - Prix > 0           → "X €" (entier sans décimale si entier, sinon 2 décimales)
 *
 * Centralisé ici pour garantir la même présentation sur toutes les pages
 * (Catalogue, Accueil, Détail, Dashboards). Si on doit changer le format
 * (ex. ajouter HT/TTC, devise), un seul endroit à modifier.
 */

/**
 * Formate un prix numérique pour affichage utilisateur.
 *
 * @param {number|string|null|undefined} prix Prix brut tel que renvoyé par l'API
 * @returns {string} Chaîne prête à afficher dans une <span>
 */
export function formaterPrix(prix) {
    // Conversion en nombre flottant. parseFloat("0.00") = 0 ; parseFloat(null) = NaN.
    const valeur = parseFloat(prix);

    // Cas "Gratuit" : null/undefined/NaN ou 0.
    if (!valeur || valeur <= 0) {
        return 'Gratuit';
    }

    // Si entier (ex. 99), on n'affiche pas de décimales pour rester compact.
    // Sinon, 2 décimales (ex. 49.99 → "49,99 €"). Number.isInteger ne fonctionne
    // pas pour 49.0, donc on compare avec Math.floor.
    const formatte = valeur === Math.floor(valeur)
        ? valeur.toString()
        : valeur.toFixed(2).replace('.', ',');

    return `${formatte} €`;
}

/**
 * Indique si une formation est gratuite (prix 0 ou non défini).
 * Utile pour appliquer un style CSS différent au badge (vert vs neutre).
 *
 * @param {number|string|null|undefined} prix
 * @returns {boolean}
 */
export function estGratuite(prix) {
    const valeur = parseFloat(prix);
    return !valeur || valeur <= 0;
}
