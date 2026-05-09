<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Contrôleur de paiement (simulé) pour les formations payantes.
 *
 * Architecture MVC stricte : Controller → Service → Model. Le contrôleur ne
 * fait que la couche HTTP (auth JWT, validation payload, mapping erreurs)
 * et délègue toute la logique métier à PaymentService.
 *
 * Endpoint exposé :
 *  - POST /api/payments/confirmer : confirme un paiement et crée l'inscription
 */
class PaymentController extends Controller
{
    /** Message uniforme pour token absent/invalide. */
    private const TOKEN_INVALID_OR_ABSENT_MESSAGE = 'Token invalide ou absent';

    /** Service injecté par constructor (Laravel résout l'instance). */
    public function __construct(private readonly PaymentService $paymentService) {}

    /**
     * Confirme un paiement après ré-authentification mot de passe.
     * Route : POST /api/payments/confirmer
     *
     * Payload attendu :
     *  - formation_id (int, required) : id de la formation à acheter
     *  - mot_de_passe (string, required) : mot de passe re-saisi par l'apprenant
     *
     * Notes : on n'attend PAS de "montant" du client — il est recalculé
     * côté serveur depuis formation.prix (anti price-tampering).
     *
     * Réponses :
     *  - 201 : inscription créée + reçu de paiement
     *  - 400 : payload invalide / formation gratuite / déjà inscrit
     *  - 401 : token JWT manquant ou ré-authentification échouée
     *  - 403 : rôle invalide (formateur tente de payer)
     *  - 404 : formation introuvable
     */
    public function confirmer(Request $request): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (! $user) {
                return response()->json(['message' => 'Utilisateur non trouvé'], 404);
            }

            // Validation structurelle du payload.
            $valide = $request->validate([
                'formation_id' => 'required|integer',
                'mot_de_passe' => 'required|string',
            ]);

            // Délégation au service. Toute violation métier remonte en DomainException.
            $resultat = $this->paymentService->confirmerEtInscrire(
                $user,
                $valide['formation_id'],
                $valide['mot_de_passe']
            );

            return response()->json([
                'message' => 'Paiement confirmé, vous êtes inscrit à la formation',
                'payment' => $resultat['payment'],
                'inscription' => $resultat['inscription'],
                // Recalculé côté serveur depuis la BDD pour transparence dans la réponse.
                'montant_paye' => $resultat['montant'],
            ], 201);

        } catch (\DomainException $e) {
            return $this->mapperErreur($e->getMessage());
        } catch (JWTException $e) {
            return response()->json(['message' => self::TOKEN_INVALID_OR_ABSENT_MESSAGE], 401);
        }
    }

    /**
     * Mappe les codes d'erreur métier de PaymentService vers les codes HTTP
     * attendus par le frontend. Centralisé pour rester DRY et faciliter les tests.
     */
    private function mapperErreur(string $code): JsonResponse
    {
        return match ($code) {
            // Mot de passe incorrect → 401 (ré-authentification a échoué).
            // C'est volontairement le même statut que JWT invalide pour ne pas
            // donner d'indice à un attaquant sur la cause de l'échec.
            PaymentService::ERREUR_MOT_DE_PASSE_INCORRECT => response()->json([
                'message' => 'Mot de passe incorrect',
                'erreur' => $code,
            ], 401),

            // Formation introuvable → 404.
            PaymentService::ERREUR_FORMATION_INTROUVABLE => response()->json([
                'message' => 'Formation introuvable',
                'erreur' => $code,
            ], 404),

            // Formation gratuite → 400 (payload incohérent : pas besoin de paiement).
            PaymentService::ERREUR_FORMATION_GRATUITE => response()->json([
                'message' => 'Cette formation est gratuite, utilisez l\'endpoint d\'inscription standard',
                'erreur' => $code,
            ], 400),

            // Déjà inscrit → 400 (idempotence, pas une erreur d'utilisation).
            PaymentService::ERREUR_DEJA_INSCRIT => response()->json([
                'message' => 'Vous êtes déjà inscrit à cette formation',
                'erreur' => $code,
            ], 400),

            // Rôle invalide → 403.
            PaymentService::ERREUR_ROLE_INVALIDE => response()->json([
                'message' => 'Seul un apprenant peut acheter une formation',
                'erreur' => $code,
            ], 403),

            // Code inconnu (ne devrait jamais arriver).
            default => response()->json(['message' => 'Erreur inconnue', 'erreur' => $code], 500),
        };
    }
}
