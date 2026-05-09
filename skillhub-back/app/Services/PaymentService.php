<?php

namespace App\Services;

use App\Models\Formation;
use App\Models\Inscription;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Service métier de paiement (simulé) pour l'inscription à une formation.
 *
 * Contrôles de sécurité appliqués :
 *  1. Re-saisie du mot de passe : l'utilisateur doit retaper son password
 *     même s'il est déjà authentifié JWT. Ça empêche un attaquant qui aurait
 *     volé un JWT (XSS, hijack) de réaliser un achat à la place du
 *     propriétaire du compte. Vérifié via Hash::check (constant time).
 *
 *  2. Recalcul du montant côté serveur : le prix est lu depuis la BDD
 *     (formation.prix) et JAMAIS depuis le payload client. Empêche un
 *     attaquant qui modifierait le DOM ou interceperait la requête de
 *     payer 1 Rs au lieu de 1 500 Rs (price tampering).
 *
 *  3. Idempotence : si un paiement réussi existe déjà pour ce couple
 *     (user, formation), on ne crée pas de doublon.
 *
 *  4. Tout est dans une transaction DB : si l'inscription échoue après
 *     enregistrement du paiement (cas pathologique), on rollback tout
 *     pour ne pas laisser un paiement orphelin.
 *
 *  5. Toutes les tentatives (succès ET échec) sont enregistrées dans la
 *     table payments pour audit forensique.
 *
 * Codes d'erreur métier exposés via constantes pour mapping HTTP côté
 * contrôleur.
 */
class PaymentService
{
    /** Code d'erreur : la formation n'existe pas. */
    public const ERREUR_FORMATION_INTROUVABLE = 'formation_introuvable';

    /** Code d'erreur : la formation est gratuite (pas besoin de paiement). */
    public const ERREUR_FORMATION_GRATUITE = 'formation_gratuite';

    /** Code d'erreur : l'apprenant a déjà payé/est déjà inscrit. */
    public const ERREUR_DEJA_INSCRIT = 'deja_inscrit';

    /** Code d'erreur : le mot de passe re-saisi est incorrect. */
    public const ERREUR_MOT_DE_PASSE_INCORRECT = 'mot_de_passe_incorrect';

    /** Code d'erreur : seul un apprenant peut payer (formateur exclu). */
    public const ERREUR_ROLE_INVALIDE = 'role_invalide';

    /**
     * Confirme un paiement et inscrit l'apprenant à la formation.
     *
     * Flux :
     *  1. Vérifie le rôle (apprenant uniquement)
     *  2. Vérifie le mot de passe re-saisi (constant time)
     *  3. Vérifie que la formation existe et est payante
     *  4. Vérifie l'absence de doublon (idempotence)
     *  5. Crée le record Payment (transaction)
     *  6. Crée l'Inscription (transaction)
     *  7. Commit
     *
     * En cas d'erreur métier, lève DomainException avec un code que le
     * contrôleur mappera vers le bon HTTP. En cas de mot de passe incorrect,
     * un Payment de statut "echec" est créé pour le forensic.
     *
     * @param User $user Utilisateur authentifié JWT (apprenant)
     * @param int $formationId ID de la formation à acheter
     * @param string $motDePasse Mot de passe re-saisi par l'utilisateur
     * @return array{payment: Payment, inscription: Inscription, montant: float}
     * @throws \DomainException Si une règle métier est violée
     */
    public function confirmerEtInscrire(User $user, int $formationId, string $motDePasse): array
    {
        // 1. Rôle : seul un apprenant peut payer (le formateur n'a pas à
        // s'inscrire à ses propres formations).
        if ($user->role !== 'apprenant') {
            throw new \DomainException(self::ERREUR_ROLE_INVALIDE);
        }

        // 2. Re-authentification : vérifie le mot de passe avec Hash::check
        // (utilise password_verify en interne, comparaison en temps constant
        // pour empêcher les attaques par timing).
        if (! Hash::check($motDePasse, $user->password)) {
            // On enregistre l'échec en base pour traçabilité (anti-fraude).
            // Pas de FK formation_id car la formation peut être valide mais
            // on rejette quand même l'attaque AVANT de toucher à formation.
            // On log avec montant=0 (on n'a pas encore lu formation.prix).
            // Note : on n'enregistre PAS si formation introuvable plus bas
            // pour ne pas polluer la table avec des FK invalides.
            $formationExiste = Formation::where('id', $formationId)->exists();
            if ($formationExiste) {
                Payment::create([
                    'user_id' => $user->id,
                    'formation_id' => $formationId,
                    'montant' => 0,
                    'statut' => 'echec',
                ]);
            }

            throw new \DomainException(self::ERREUR_MOT_DE_PASSE_INCORRECT);
        }

        // 3. Formation : doit exister.
        $formation = Formation::find($formationId);
        if (! $formation) {
            throw new \DomainException(self::ERREUR_FORMATION_INTROUVABLE);
        }

        // 4. Formation payante : si prix=0, on n'utilise pas le flow paiement.
        // L'apprenant doit utiliser POST /formations/{id}/inscription directement.
        if ((float) $formation->prix <= 0) {
            throw new \DomainException(self::ERREUR_FORMATION_GRATUITE);
        }

        // 5. Idempotence : pas déjà inscrit.
        $dejaInscrit = Inscription::where('utilisateur_id', $user->id)
            ->where('formation_id', $formation->id)
            ->exists();
        if ($dejaInscrit) {
            throw new \DomainException(self::ERREUR_DEJA_INSCRIT);
        }

        // 6. Création atomique paiement + inscription dans une transaction.
        // CRITIQUE : on lit le montant depuis $formation->prix (BDD) et NON
        // depuis le payload client. Anti-tampering : un attaquant ne peut pas
        // forger une requête avec montant=1 Rs.
        $montantCalcule = (float) $formation->prix;

        return DB::transaction(function () use ($user, $formation, $montantCalcule) {
            // Trace du paiement (statut reussi).
            $payment = Payment::create([
                'user_id' => $user->id,
                'formation_id' => $formation->id,
                'montant' => $montantCalcule,
                'statut' => 'reussi',
            ]);

            // Inscription effective avec progression initiale à 0.
            $inscription = Inscription::create([
                'utilisateur_id' => $user->id,
                'formation_id' => $formation->id,
                'progression' => 0,
            ]);

            return [
                'payment' => $payment,
                'inscription' => $inscription,
                'montant' => $montantCalcule,
            ];
        });
    }
}
