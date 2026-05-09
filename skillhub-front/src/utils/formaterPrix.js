/*
 * Helper de formatage du prix d'une formation pour l'affichage sur les cartes.
 *
 * Devise : roupie mauricienne (MUR).
 * Format : "Rs 1,500" (entier) ou "Rs 49.99" (décimal), avec separateur de
 * milliers en virgule (convention en-MU).
 *
 * Règle d'affichage :
 *   - Prix null, 0 ou 0.00 → "Gratuit"
 *   - Prix > 0           → "Rs <montant>"
 *
 * Centralisé ici pour garantir la même présentation sur toutes les pages
 * (Catalogue, Accueil, Détail, Dashboards). Si on doit changer le format
 * (ex. autre devise, ajouter HT/TTC), un seul endroit à modifier.
 */

/**
 * Formate un prix numérique pour affichage utilisateur en roupies mauriciennes.
 *
 * Exemples :
 *   formaterPrix(0)      → "Gratuit"
 *   formaterPrix(null)   → "Gratuit"
 *   formaterPrix(1500)   → "Rs 1,500"
 *   formaterPrix(2499.5) → "Rs 2,499.50"
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

    // Format mauricien (en-MU) : virgule comme séparateur de milliers,
    // point comme séparateur décimal. Si entier, pas de décimales (ex.
    // "Rs 1,500"). Si décimal, 2 chiffres après le point ("Rs 49.99").
    const estEntier = valeur === Math.floor(valeur);
    const formatte = new Intl.NumberFormat('en-MU', {
        minimumFractionDigits: estEntier ? 0 : 2,
        maximumFractionDigits: 2,
    }).format(valeur);

    return `Rs ${formatte}`;
}

/**
 * Indique si une formation est gratuite (prix 0 ou non défini).
 * Utile pour appliquer un style CSS différent au badge (vert vs orange/neutre).
 *
 * @param {number|string|null|undefined} prix
 * @returns {boolean}
 */
export function estGratuite(prix) {
    const valeur = parseFloat(prix);
    return !valeur || valeur <= 0;
}
