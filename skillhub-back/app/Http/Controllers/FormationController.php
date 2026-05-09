<?php

namespace App\Http\Controllers;

use App\Models\Formation;
use App\Models\FormationVue;
use App\Models\Inscription;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

/**
 * Contrôleur de gestion des formations SkillHub.
 *
 * Ce contrôleur gère le cycle de vie complet d'une formation : liste publique
 * et privée (mes formations en tant que formateur), affichage avec comptage des
 * vues uniques, création, modification, suppression, et upload/téléchargement
 * du fichier PDF du cours. Toutes les opérations d'écriture sont réservées
 * au formateur propriétaire de la formation.
 */
class FormationController extends Controller
{
    // Messages d'erreur centralisés.
    private const USER_NOT_FOUND_MESSAGE = 'Utilisateur non trouvé';
    private const TOKEN_INVALID_OR_ABSENT_MESSAGE = 'Token invalide ou absent';
    private const FORMATION_NOT_FOUND_MESSAGE = 'Formation introuvable';

    /**
     * Liste les formations créées par le formateur connecté.
     * Route : GET /api/formateur/mes-formations
     *
     * Accès : utilisateur authentifié avec le rôle "formateur" uniquement.
     * Charge en plus l'objet formateur (id/nom/email) et le nombre d'inscriptions.
     */
    public function mesFormations(): JsonResponse
    {
        try {
            // Authentification via JWT.
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return response()->json(['message' => self::USER_NOT_FOUND_MESSAGE], 404);
            }

            // Cette route est strictement réservée aux formateurs.
            if ($user->role !== 'formateur') {
                return response()->json(['message' => 'Accès réservé aux formateurs'], 403);
            }

            // Eager loading pour éviter les requêtes N+1, avec compteur d'inscriptions.
            $formations = Formation::with('formateur:id,nom,email')
                ->withCount('inscriptions')
                ->where('formateur_id', $user->id)
                ->get();

            return response()->json($formations);

        } catch (JWTException $e) {
            return response()->json(['message' => self::TOKEN_INVALID_OR_ABSENT_MESSAGE], 401);
        }
    }

    /**
     * Liste publique des formations avec filtres optionnels.
     * Route : GET /api/formations
     *
     * Filtres disponibles via query string : recherche (texte), categorie, niveau.
     * Accessible sans authentification.
     */
    public function index(Request $request): JsonResponse
    {
        // Construction de la requête avec eager loading et comptage d'inscriptions.
        $query = Formation::with('formateur:id,nom,email')
            ->withCount('inscriptions');

        // Filtre texte : recherche dans le titre OU la description (LIKE %motcle%).
        if ($request->filled('recherche')) {
            $motCle = $request->input('recherche');

            $query->where(function ($q) use ($motCle) {
                $q->where('titre', 'like', '%' . $motCle . '%')
                    ->orWhere('description', 'like', '%' . $motCle . '%');
            });
        }

        // Filtre catégorie exacte.
        if ($request->filled('categorie')) {
            $query->where('categorie', $request->input('categorie'));
        }

        // Filtre niveau exact.
        if ($request->filled('niveau')) {
            $query->where('niveau', $request->input('niveau'));
        }

        return response()->json($query->get());
    }

    /**
     * Affiche le détail d'une formation et incrémente son compteur de vues.
     * Route : GET /api/formations/{id}
     *
     * Logique de comptage :
     *  - Le formateur propriétaire ne génère JAMAIS de vue sur sa propre formation.
     *  - Un apprenant connecté est compté UNE SEULE FOIS (contrainte unique formation_id+utilisateur_id).
     *  - Un visiteur anonyme est compté UNE SEULE FOIS par IP.
     *
     * Accessible sans authentification (le token est lu en best-effort).
     */
    public function show(Request $request, $id): JsonResponse
    {
        // Recherche de la formation avec ses relations.
        $formation = Formation::with('formateur:id,nom,email')
            ->withCount('inscriptions')
            ->find($id);

        if (! $formation) {
            return response()->json([
                'message' => self::FORMATION_NOT_FOUND_MESSAGE
            ], 404);
        }

        // On tente d'identifier l'utilisateur (token optionnel ici).
        $utilisateurId = null;
        $estProprietaire = false;

        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user) {
                $utilisateurId = $user->id;
                // Un formateur qui voit sa propre formation ne doit pas inflater le compteur.
                $estProprietaire = $user->id === $formation->formateur_id;
            }
        } catch (JWTException $e) {
            // Aucun token ou token invalide : on traite comme visiteur anonyme.
        }

        if ($estProprietaire) {
            // Cas explicite : pas de vue comptée pour le formateur lui-même.
        } elseif ($utilisateurId) {
            // Apprenant connecté : firstOrCreate garantit l'unicité (contrainte BDD).
            try {
                $created = FormationVue::firstOrCreate(
                    [
                        'formation_id'   => $formation->id,
                        'utilisateur_id' => $utilisateurId,
                    ],
                    ['ip' => $request->ip()]
                );

                // wasRecentlyCreated = true uniquement à la première vue.
                if ($created->wasRecentlyCreated) {
                    $formation->increment('nombre_de_vues');
                }
            } catch (\Throwable $e) {
                // Course condition possible : autre requête a inséré entre-temps. On ignore.
            }
        } else {
            // Visiteur anonyme : on identifie par couple (formation, IP).
            try {
                $created = FormationVue::firstOrCreate(
                    [
                        'formation_id'   => $formation->id,
                        'utilisateur_id' => null,
                        'ip'             => $request->ip(),
                    ]
                );

                if ($created->wasRecentlyCreated) {
                    $formation->increment('nombre_de_vues');
                }
            } catch (\Throwable $e) {
                // Idem, on ignore les violations de contrainte unique.
            }
        }

        // Log MongoDB pour analyse comportementale (consultations dans le temps).
        try {
            ActivityLogService::consultationFormation($formation->id, $utilisateurId);
        } catch (\Throwable $e) {
            // Mongo indisponible n'empêche pas la réponse principale.
            \Log::warning('ActivityLog indisponible (show): ' . $e->getMessage());
        }

        // refresh + reload pour renvoyer la donnée à jour (le compteur peut avoir changé).
        $formation->refresh();
        $formation->load('formateur:id,nom,email');
        $formation->loadCount('inscriptions');

        return response()->json($formation);
    }

    /**
     * Crée une nouvelle formation (avec PDF optionnel).
     * Route : POST /api/formations
     *
     * Accès : formateur authentifié uniquement.
     * Le PDF est sauvegardé sous storage/app/public/formations/{id}/cours.pdf.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return response()->json([
                    'message' => self::USER_NOT_FOUND_MESSAGE
                ], 404);
            }

            // Seul un formateur peut créer une formation.
            if ($user->role !== 'formateur') {
                return response()->json([
                    'message' => 'Seul un formateur peut créer une formation'
                ], 403);
            }

            // Validation : PDF et image sont optionnels (mimes restrictifs et tailles max).
            $request->validate([
                'titre' => 'required|string|max:255',
                'description' => 'required|string',
                'categorie' => 'required|in:developpement_web,data,design,marketing,devops,autre',
                'niveau' => 'required|in:debutant,intermediaire,avance',
                'prix' => 'nullable|numeric|min:0',
                'duree_heures' => 'nullable|integer|min:0',
                'fichier_pdf' => 'nullable|file|mimes:pdf|max:10240',
                // Image illustrative — formats web courants, max 2 MB pour rester léger sur le catalogue.
                'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            ]);

            // Création de la formation, le formateur propriétaire est l'utilisateur connecté.
            $formation = Formation::create([
                'titre' => $request->input('titre'),
                'description' => $request->input('description'),
                'categorie' => $request->input('categorie'),
                'niveau' => $request->input('niveau'),
                'prix' => $request->input('prix', 0),
                'duree_heures' => $request->input('duree_heures', 0),
                'nombre_de_vues' => 0,
                'formateur_id' => $user->id,
            ]);

            // Si un PDF est joint, on le stocke dans le disque "public" (lien symbolique vers public/storage/).
            if ($request->hasFile('fichier_pdf')) {
                $chemin = $request->file('fichier_pdf')->storeAs(
                    "formations/{$formation->id}",  // Sous-dossier propre par formation.
                    'cours.pdf',                    // Nom standardisé.
                    'public'                        // Disque public.
                );
                $formation->update(['fichier_pdf' => $chemin]);
            }

            // Si une image est jointe, on la stocke dans le même sous-dossier que le PDF.
            // L'extension est préservée pour que le navigateur reconnaisse le bon MIME.
            if ($request->hasFile('image')) {
                $extension = $request->file('image')->getClientOriginalExtension();
                $cheminImage = $request->file('image')->storeAs(
                    "formations/{$formation->id}",
                    "image.{$extension}",
                    'public'
                );
                $formation->update(['image' => $cheminImage]);
            }

            // Log MongoDB de création (best-effort, n'empêche pas la création principale).
            try {
                ActivityLogService::creationFormation($formation->id, $user->id);
            } catch (\Throwable $e) {
                \Log::warning('ActivityLog indisponible (store): ' . $e->getMessage());
            }

            return response()->json([
                'message' => 'Formation créée avec succès',
                'formation' => $formation
            ], 201);

        } catch (JWTException $e) {
            return response()->json([
                'message' => self::TOKEN_INVALID_OR_ABSENT_MESSAGE
            ], 401);
        }
    }

    /**
     * Met à jour une formation existante (avec remplacement éventuel du PDF).
     * Route : PUT /api/formations/{id}
     *
     * Accès : seul le formateur propriétaire peut modifier sa formation.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return response()->json([
                    'message' => self::USER_NOT_FOUND_MESSAGE
                ], 404);
            }

            $formation = Formation::find($id);

            if (! $formation) {
                return response()->json([
                    'message' => self::FORMATION_NOT_FOUND_MESSAGE
                ], 404);
            }

            // Vérification de propriété : seul le formateur d'origine peut modifier.
            if ($user->role !== 'formateur' || $formation->formateur_id !== $user->id) {
                return response()->json([
                    'message' => 'Action non autorisée'
                ], 403);
            }

            $request->validate([
                'titre' => 'required|string|max:255',
                'description' => 'required|string',
                'categorie' => 'required|in:developpement_web,data,design,marketing,devops,autre',
                'niveau' => 'required|in:debutant,intermediaire,avance',
                'prix' => 'nullable|numeric|min:0',
                'duree_heures' => 'nullable|integer|min:0',
                'fichier_pdf' => 'nullable|file|mimes:pdf|max:10240',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            ]);

            // Mémorisation des valeurs avant modification (pour l'ActivityLog).
            $oldValues = $formation->getOriginal();

            // Mise à jour des champs scalaires (sans toucher au PDF si non fourni).
            $formation->update([
                'titre' => $request->input('titre'),
                'description' => $request->input('description'),
                'categorie' => $request->input('categorie'),
                'niveau' => $request->input('niveau'),
                'prix' => $request->input('prix', $formation->prix),
                'duree_heures' => $request->input('duree_heures', $formation->duree_heures),
            ]);

            // Si un nouveau PDF est uploadé, on supprime l'ancien et on le remplace.
            if ($request->hasFile('fichier_pdf')) {
                if ($formation->fichier_pdf) {
                    Storage::disk('public')->delete($formation->fichier_pdf);
                }
                $chemin = $request->file('fichier_pdf')->storeAs(
                    "formations/{$formation->id}",
                    'cours.pdf',
                    'public'
                );
                $formation->update(['fichier_pdf' => $chemin]);
            }

            // Si une nouvelle image est uploadée, on supprime l'ancienne et on la remplace.
            // Note : l'extension peut changer entre l'ancienne (image.jpg) et la nouvelle (image.png),
            // d'où l'importance de bien supprimer l'ancien chemin stocké en DB avant d'écraser.
            if ($request->hasFile('image')) {
                if ($formation->image) {
                    Storage::disk('public')->delete($formation->image);
                }
                $extension = $request->file('image')->getClientOriginalExtension();
                $cheminImage = $request->file('image')->storeAs(
                    "formations/{$formation->id}",
                    "image.{$extension}",
                    'public'
                );
                $formation->update(['image' => $cheminImage]);
            }

            // Log Mongo avec diff before/after pour audit.
            try {
                ActivityLogService::modificationFormation($formation->id, $user->id, $oldValues, $formation->getChanges());
            } catch (\Throwable $e) {
                \Log::warning('ActivityLog indisponible (update): ' . $e->getMessage());
            }

            return response()->json([
                'message' => 'Formation mise à jour avec succès',
                'formation' => $formation
            ]);

        } catch (JWTException $e) {
            return response()->json([
                'message' => self::TOKEN_INVALID_OR_ABSENT_MESSAGE
            ], 401);
        }
    }

    /**
     * Supprime une formation et son PDF associé.
     * Route : DELETE /api/formations/{id}
     *
     * Accès : seul le formateur propriétaire.
     * Cascade : les modules, inscriptions et vues sont supprimés via les contraintes FK.
     */
    public function destroy($id): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return response()->json([
                    'message' => self::USER_NOT_FOUND_MESSAGE
                ], 404);
            }

            $formation = Formation::find($id);

            if (! $formation) {
                return response()->json([
                    'message' => self::FORMATION_NOT_FOUND_MESSAGE
                ], 404);
            }

            // Contrôle de propriété.
            if ($user->role !== 'formateur' || $formation->formateur_id !== $user->id) {
                return response()->json([
                    'message' => 'Action non autorisée'
                ], 403);
            }

            // Log Mongo AVANT la suppression (sinon l'id est perdu).
            try {
                ActivityLogService::suppressionFormation($formation->id, $user->id);
            } catch (\Throwable $e) {
                \Log::warning('ActivityLog indisponible (destroy): ' . $e->getMessage());
            }

            // Nettoyage du fichier PDF du disque pour ne pas laisser de fichiers orphelins.
            if ($formation->fichier_pdf) {
                Storage::disk('public')->delete($formation->fichier_pdf);
            }

            // Idem pour l'image illustrative.
            if ($formation->image) {
                Storage::disk('public')->delete($formation->image);
            }

            // delete() déclenche le cascade ON DELETE des migrations (modules, inscriptions, vues).
            $formation->delete();

            return response()->json([
                'message' => 'Formation supprimée avec succès'
            ]);

        } catch (JWTException $e) {
            return response()->json([
                'message' => self::TOKEN_INVALID_OR_ABSENT_MESSAGE
            ], 401);
        }
    }

    /**
     * Télécharge le PDF du cours d'une formation.
     * Route : GET /api/formations/{id}/pdf
     *
     * Règles d'accès :
     *  - Le formateur propriétaire peut toujours télécharger.
     *  - Un apprenant doit être inscrit (entrée dans la table inscriptions).
     *  - Tout autre utilisateur reçoit un 403.
     *
     * Le téléchargement se fait via stream pour économiser la mémoire sur les gros fichiers.
     */
    public function downloadPdf($id): StreamedResponse|JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return response()->json(['message' => self::USER_NOT_FOUND_MESSAGE], 404);
            }

            $formation = Formation::find($id);

            if (! $formation) {
                return response()->json(['message' => self::FORMATION_NOT_FOUND_MESSAGE], 404);
            }

            // Si la formation n'a jamais eu de PDF, on évite d'aller chercher sur le disque.
            if (! $formation->fichier_pdf) {
                return response()->json(['message' => 'Aucun fichier PDF disponible pour cette formation'], 404);
            }

            // Calcul des droits d'accès.
            $estProprietaire = $user->id === $formation->formateur_id;
            $estInscrit = Inscription::where('formation_id', $formation->id)
                ->where('utilisateur_id', $user->id)
                ->exists();

            if (! $estProprietaire && ! $estInscrit) {
                return response()->json([
                    'message' => 'Vous devez être inscrit à cette formation pour télécharger le PDF'
                ], 403);
            }

            // Sécurité : on revérifie que le fichier existe physiquement avant de streamer.
            if (! Storage::disk('public')->exists($formation->fichier_pdf)) {
                return response()->json(['message' => 'Fichier introuvable sur le serveur'], 404);
            }

            // Nom de téléchargement basé sur le titre, nettoyé de tout caractère non-ASCII.
            $nomTelechargement = preg_replace('/[^A-Za-z0-9_\-]/', '_', $formation->titre) . '.pdf';

            // download() retourne une StreamedResponse avec les bons headers Content-Disposition.
            return Storage::disk('public')->download($formation->fichier_pdf, $nomTelechargement);

        } catch (JWTException $e) {
            return response()->json(['message' => self::TOKEN_INVALID_OR_ABSENT_MESSAGE], 401);
        }
    }
}
