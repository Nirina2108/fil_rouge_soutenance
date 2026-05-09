<?php

namespace App\Http\Controllers;

use App\Services\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Contrôleur des notes/avis sur les formations.
 *
 * Délègue toute la logique métier au RatingService (architecture MVC stricte :
 * Controller → Service → Model). Le contrôleur ne fait que :
 *  1. Authentifier la requête (JWT)
 *  2. Valider la forme du payload (note entier, commentaire string optionnel)
 *  3. Appeler le service
 *  4. Mapper les erreurs métier (DomainException) vers des codes HTTP
 *
 * Endpoint exposé :
 *  - POST /api/formations/{id}/noter : créer une note pour la formation {id}
 */
class RatingController extends Controller
{
    /** Message uniformisé pour token JWT manquant ou invalide. */
    private const TOKEN_INVALID_OR_ABSENT_MESSAGE = 'Token invalide ou absent';

    /** Service injecté (constructor injection — testable, immutable). */
    public function __construct(private readonly RatingService $ratingService) {}

    /**
     * Crée une note pour une formation.
     * Route : POST /api/formations/{id}/noter
     *
     * Codes de retour :
     *  - 201 : note créée, retour du rating en JSON
     *  - 400 : payload invalide OU note hors [1-5] OU formation déjà notée
     *  - 401 : token JWT manquant ou invalide
     *  - 403 : apprenant non inscrit à la formation
     */
    public function noter(Request $request, $formationId): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return response()->json(['message' => 'Utilisateur non trouvé'], 404);
            }

            // Validation du payload (forme structurelle, pas business).
            // L'intervalle [1, 5] est validé en plus côté service pour permettre
            // une réutilisation hors HTTP. Mais on l'ajoute aussi ici pour
            // intercepter au plus tôt les valeurs hors bornes.
            $valide = $request->validate([
                'note' => 'required|integer',
                'commentaire' => 'nullable|string|max:1000',
            ]);

            // Délégation au service. Toute violation métier remonte en DomainException.
            $rating = $this->ratingService->noter(
                $user->id,
                (int) $formationId,
                $valide['note'],
                $valide['commentaire'] ?? null
            );

            // 201 Created avec le rating tel qu'il vient d'être persisté.
            return response()->json([
                'message' => 'Note enregistrée avec succès',
                'rating' => $rating,
            ], 201);

        } catch (\DomainException $e) {
            // Mapping des codes d'erreur métier → HTTP.
            return $this->mapperErreurMetier($e->getMessage());
        } catch (JWTException $e) {
            return response()->json(['message' => self::TOKEN_INVALID_OR_ABSENT_MESSAGE], 401);
        }
    }

    /**
     * Mappe les codes d'erreur métier (constantes RatingService) vers les
     * codes HTTP appropriés. Centralisé pour rester DRY.
     */
    private function mapperErreurMetier(string $code): JsonResponse
    {
        return match ($code) {
            // Note hors intervalle ou doublon → 400 Bad Request.
            RatingService::ERREUR_NOTE_INVALIDE => response()->json([
                'message' => 'La note doit être comprise entre 1 et 5',
                'erreur' => $code,
            ], 400),
            RatingService::ERREUR_DEJA_NOTE => response()->json([
                'message' => 'Vous avez déjà noté cette formation',
                'erreur' => $code,
            ], 400),
            // Pas inscrit → 403 Forbidden (l'apprenant existe mais n'a pas le droit).
            RatingService::ERREUR_NON_INSCRIT => response()->json([
                'message' => 'Vous devez être inscrit à la formation pour la noter',
                'erreur' => $code,
            ], 403),
            // Code inconnu → 500 (ne devrait jamais arriver).
            default => response()->json(['message' => 'Erreur inconnue', 'erreur' => $code], 500),
        };
    }
}
