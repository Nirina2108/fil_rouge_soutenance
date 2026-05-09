<?php

namespace App\Services;

use App\Models\Formation;
use App\Models\Inscription;
use Illuminate\Support\Collection;

/**
 * Service métier pour récupérer la liste des apprenants inscrits à une formation.
 *
 * Centralise la logique d'accès et de validation pour respecter l'architecture
 * MVC : Controller → Service → Model. Le contrôleur ne fait que les
 * vérifications HTTP (auth, validation de payload) et délègue ici la
 * récupération + le contrôle de propriété formateur.
 *
 * Codes d'erreur métier exposés via des constantes pour mapping vers HTTP
 * dans le contrôleur.
 */
class ApprenantsInscritsService
{
    /** Code d'erreur : la formation demandée n'existe pas en base. */
    public const ERREUR_FORMATION_INTROUVABLE = 'formation_introuvable';

    /** Code d'erreur : le formateur appelant n'est pas propriétaire de la formation. */
    public const ERREUR_NON_PROPRIETAIRE = 'non_proprietaire';

    /**
     * Liste les apprenants inscrits à une formation, pour un formateur propriétaire.
     *
     * Vérifie successivement :
     *  - la formation existe (sinon ERREUR_FORMATION_INTROUVABLE → 404)
     *  - le formateur appelant est bien propriétaire (sinon ERREUR_NON_PROPRIETAIRE → 403)
     *
     * Retourne une Collection de tableaux associatifs avec les champs imposés
     * par la spécification : id, nom, email, progression, date_inscription.
     *
     * Si aucun apprenant n'est inscrit, retourne une Collection vide
     * (le contrôleur la sérialise en tableau JSON vide []).
     *
     * @param int $formationId ID de la formation à inspecter
     * @param int $formateurId ID du formateur qui consulte (pour vérif propriété)
     * @return Collection Liste des apprenants au format spécifié
     * @throws \DomainException Si formation introuvable ou non propriétaire
     */
    public function listerApprenantsInscrits(int $formationId, int $formateurId): Collection
    {
        // Étape 1 : la formation doit exister.
        $formation = Formation::find($formationId);

        if (! $formation) {
            throw new \DomainException(self::ERREUR_FORMATION_INTROUVABLE);
        }

        // Étape 2 : le formateur appelant doit être le propriétaire.
        // C'est une règle de sécurité, pas juste une autorisation : un autre
        // formateur n'a pas a voir la liste des apprenants d'une formation
        // dont il n'est pas l'auteur (RGPD côté apprenants).
        if ($formation->formateur_id !== $formateurId) {
            throw new \DomainException(self::ERREUR_NON_PROPRIETAIRE);
        }

        // Étape 3 : récupération des inscriptions avec eager-loading du user.
        // On charge UNIQUEMENT les colonnes nécessaires sur users (id, nom, email)
        // pour limiter la taille du payload (pas de mot de passe ni de photo_profil
        // qui ne servent pas dans cette vue).
        $inscriptions = Inscription::with('utilisateur:id,nom,email')
            ->where('formation_id', $formationId)
            ->get();

        // Étape 4 : transformation au format attendu (id, nom, email, progression,
        // date_inscription). On garde le mapping ici dans le service plutôt que
        // dans le contrôleur pour respecter la séparation des responsabilités.
        return $inscriptions->map(function (Inscription $inscription) {
            return [
                'id' => $inscription->utilisateur->id,
                'nom' => $inscription->utilisateur->nom,
                'email' => $inscription->utilisateur->email,
                'progression' => (int) $inscription->progression,
                // created_at de la table inscriptions = moment où l'apprenant
                // s'est inscrit. On formate en ISO 8601 pour le frontend.
                'date_inscription' => $inscription->created_at?->toIso8601String(),
            ];
        });
    }
}
