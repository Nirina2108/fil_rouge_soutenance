<?php

namespace App\Services;

use App\Models\Inscription;
use App\Models\Rating;
use Illuminate\Database\QueryException;

/**
 * Service métier pour la notation des formations.
 *
 * Centralise la logique de validation et persistance pour respecter
 * l'architecture MVC : Controller → Service → Model.
 *
 * Le contrôleur RatingController appelle uniquement les méthodes publiques
 * de ce service ; il n'accède jamais directement aux modèles Rating ou
 * Inscription. Cela permet de tester la logique métier indépendamment
 * du couche HTTP et de centraliser les règles (intervalle, unicité,
 * inscription requise) en un seul endroit.
 *
 * Codes d'erreur métier exposés via des constantes pour faciliter le mapping
 * vers les codes HTTP côté contrôleur.
 */
class RatingService
{
    /** Note minimum acceptée (validation [1-5]). */
    public const NOTE_MIN = 1;

    /** Note maximum acceptée (validation [1-5]). */
    public const NOTE_MAX = 5;

    /** Code d'erreur : note hors intervalle [1-5]. */
    public const ERREUR_NOTE_INVALIDE = 'note_invalide';

    /** Code d'erreur : apprenant n'est pas inscrit à la formation. */
    public const ERREUR_NON_INSCRIT = 'non_inscrit';

    /** Code d'erreur : apprenant a déjà noté cette formation. */
    public const ERREUR_DEJA_NOTE = 'deja_note';

    /**
     * Crée une nouvelle note pour une formation, après validation des règles métier :
     *  - la note doit être dans [1, 5] (sinon ERREUR_NOTE_INVALIDE → 400)
     *  - l'apprenant doit être inscrit à la formation (sinon ERREUR_NON_INSCRIT → 403)
     *  - l'apprenant ne doit pas déjà avoir noté (sinon ERREUR_DEJA_NOTE → 400)
     *
     * En cas de violation, lève \DomainException avec le code dans le message.
     * Le contrôleur catche cette exception et renvoie le bon code HTTP.
     *
     * @param int $userId ID de l'apprenant qui note
     * @param int $formationId ID de la formation à noter
     * @param int $note Note attribuée (1 à 5)
     * @param string|null $commentaire Commentaire optionnel
     * @return Rating Le rating fraîchement créé
     * @throws \DomainException Si une règle métier est violée
     */
    public function noter(int $userId, int $formationId, int $note, ?string $commentaire = null): Rating
    {
        // Règle 1 : note dans l'intervalle [1, 5].
        // Validée côté service et non au contrôleur pour pouvoir réutiliser
        // la logique dans d'autres contextes (CLI, jobs, etc.).
        if ($note < self::NOTE_MIN || $note > self::NOTE_MAX) {
            throw new \DomainException(self::ERREUR_NOTE_INVALIDE);
        }

        // Règle 2 : l'apprenant doit être inscrit à la formation.
        // exists() est plus rapide que first() — pas de hydration de modèle.
        $estInscrit = Inscription::where('utilisateur_id', $userId)
            ->where('formation_id', $formationId)
            ->exists();

        if (! $estInscrit) {
            throw new \DomainException(self::ERREUR_NON_INSCRIT);
        }

        // Règle 3 : pas de doublon (vérifié AVANT l'INSERT pour avoir un message clair).
        // La contrainte UNIQUE (user_id, formation_id) en BDD est la garde finale.
        $dejaNote = Rating::where('user_id', $userId)
            ->where('formation_id', $formationId)
            ->exists();

        if ($dejaNote) {
            throw new \DomainException(self::ERREUR_DEJA_NOTE);
        }

        // Création. Si malgré la pré-vérif un doublon passe (race condition rare),
        // on intercepte la QueryException pour renvoyer un message cohérent.
        try {
            return Rating::create([
                'user_id' => $userId,
                'formation_id' => $formationId,
                'note' => $note,
                'commentaire' => $commentaire,
            ]);
        } catch (QueryException $e) {
            // Code SQLSTATE 23000 = violation de contrainte d'intégrité (UNIQUE).
            if ($e->getCode() === '23000') {
                throw new \DomainException(self::ERREUR_DEJA_NOTE);
            }
            throw $e;
        }
    }

    /**
     * Calcule les statistiques de notation d'une formation : moyenne arrondie
     * à 2 décimales et nombre total d'avis.
     *
     * Utilisée par FormationController::show() pour enrichir la réponse
     * GET /api/formations/{id} avec les champs note_moyenne et nombre_avis.
     *
     * Si la formation n'a aucun rating, retourne ['note_moyenne' => 0,
     * 'nombre_avis' => 0] (et non null) pour simplifier le rendu côté front.
     *
     * @param int $formationId ID de la formation
     * @return array{note_moyenne: float, nombre_avis: int}
     */
    public function calculerStatistiques(int $formationId): array
    {
        // Une seule requête SQL pour récupérer count + moyenne (plus efficace
        // que 2 requêtes séparées).
        $statistiques = Rating::where('formation_id', $formationId)
            ->selectRaw('COUNT(*) as nombre, AVG(note) as moyenne')
            ->first();

        // count peut être 0 (formation jamais notée) → on renvoie 0/0 explicite.
        $nombre = (int) ($statistiques->nombre ?? 0);
        $moyenne = $nombre > 0 ? round((float) $statistiques->moyenne, 2) : 0.0;

        return [
            'note_moyenne' => $moyenne,
            'nombre_avis' => $nombre,
        ];
    }
}
