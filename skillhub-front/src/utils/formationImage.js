/*
 * Helper d'attribution d'image à une formation.
 *
 * Stratégie : rotation déterministe sur les 7 photos bundlées dans
 * /public/images/formation/. Une formation donnée garde TOUJOURS la même
 * image (basée sur son id), ce qui évite que l'image change à chaque rendu
 * et garantit une cohérence visuelle entre les pages (catalogue, dashboard,
 * détail, etc.).
 *
 * Pas d'upload côté formateur : décision produit pour garantir une qualité
 * visuelle uniforme du catalogue.
 */

// Liste des fichiers présents dans /public/images/formation/. Ordre figé pour
// que la rotation reste stable même si on en ajoute d'autres plus tard
// (les nouveaux fichiers s'ajoutent en fin de tableau).
const IMAGES_FORMATION = [
    '1.jpg',
    '2.jpg',
    '3.jpg',
    '4.jpg',
    '5.webp',
    '6.jpg',
    '7.webp',
];

/**
 * Retourne l'URL de l'image à afficher pour une formation donnée.
 *
 * Rotation : id 1 → 1.jpg, id 2 → 2.jpg, ..., id 7 → 7.webp, id 8 → 1.jpg, etc.
 * En mode création (pas encore d'id), on affiche la 1re image comme exemple.
 *
 * @param {object|null|undefined} formation Objet formation (ou null en création)
 * @returns {string} URL absolue à donner à <img src="..." />
 */
export function getFormationImage(formation) {
    // ?? gère les cas où formation est null/undefined ou n'a pas d'id encore.
    const id = formation?.id ?? 1;
    // Modulo : on retombe sur 0..N-1. Si id = 1, on veut index 0 (1.jpg).
    // (id - 1 + length) % length : ajoute length pour gérer un id=0 hypothétique.
    const index = ((id - 1) % IMAGES_FORMATION.length + IMAGES_FORMATION.length) % IMAGES_FORMATION.length;
    return `/images/formation/${IMAGES_FORMATION[index]}`;
}
