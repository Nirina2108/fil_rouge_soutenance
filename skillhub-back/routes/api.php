<?php

/*
 * Définition des routes de l'API REST SkillHub.
 *
 * Convention de nommage :
 *  - Les chemins fréquents sont déclarés en constantes pour éviter les doublons
 *    et faciliter un éventuel renommage (un seul endroit à modifier).
 *
 * Organisation :
 *  - Routes publiques (test, register, login, listes lecture) hors groupe middleware.
 *  - Routes protégées dans Route::middleware('auth:api')->group(...) : exigent un JWT valide.
 *  - Route OPTIONS catch-all en fin pour le préflight CORS, complétée par CorsMiddleware.
 */

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FormationController;
use App\Http\Controllers\InscriptionController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

// Constantes de chemins. Le if (! defined(...)) évite les doubles définitions
// (utile en environnement de tests qui réinclut le fichier plusieurs fois).
if (! defined('ROUTE_FORMATION_BY_ID')) {
    define('ROUTE_FORMATION_BY_ID', '/formations/{id}');
}

if (! defined('ROUTE_FORMATIONS')) {
    define('ROUTE_FORMATIONS', '/formations');
}

if (! defined('ROUTE_FORMATION_MODULES')) {
    define('ROUTE_FORMATION_MODULES', '/formations/{id}/modules');
}

if (! defined('ROUTE_FORMATION_MODULES_TERMINES')) {
    define('ROUTE_FORMATION_MODULES_TERMINES', '/formations/{id}/modules-termines');
}

if (! defined('ROUTE_FORMATION_INSCRIPTION')) {
    define('ROUTE_FORMATION_INSCRIPTION', '/formations/{id}/inscription');
}

if (! defined('ROUTE_MODULE_BY_ID')) {
    define('ROUTE_MODULE_BY_ID', '/modules/{id}');
}

if (! defined('ROUTE_MODULE_TERMINER')) {
    define('ROUTE_MODULE_TERMINER', '/modules/{id}/terminer');
}

if (! defined('ROUTE_MESSAGES_NON_LUS')) {
    define('ROUTE_MESSAGES_NON_LUS', '/messages/non-lus');
}

if (! defined('ROUTE_MESSAGES_CONVERSATIONS')) {
    define('ROUTE_MESSAGES_CONVERSATIONS', '/messages/conversations');
}

if (! defined('ROUTE_MESSAGES_CONVERSATION')) {
    define('ROUTE_MESSAGES_CONVERSATION', '/messages/conversation/{interlocuteurId}');
}

if (! defined('ROUTE_MESSAGES_ENVOYER')) {
    define('ROUTE_MESSAGES_ENVOYER', '/messages/envoyer');
}

if (! defined('ROUTE_MESSAGES_INTERLOCUTEURS')) {
    define('ROUTE_MESSAGES_INTERLOCUTEURS', '/messages/interlocuteurs');
}

if (! defined('ROUTE_FORMATEUR_MES_FORMATIONS')) {
    define('ROUTE_FORMATEUR_MES_FORMATIONS', '/formateur/mes-formations');
}

if (! defined('ROUTE_APPRENANT_FORMATIONS')) {
    define('ROUTE_APPRENANT_FORMATIONS', '/apprenant/formations');
}

// Endpoint de test : permet de vérifier rapidement que l'API répond et est joignable.
Route::get('/test', function () {
    return response()->json(['message' => 'API SkillHub OK']);
});

// Authentification publique (pas de token requis pour s'inscrire ou se connecter).
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Toutes les routes nécessitant un JWT valide sont regroupées ici via le guard auth:api.
Route::middleware('auth:api')->group(function () {
    // Profil utilisateur, déconnexion, upload photo profil.
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/profil/photo', [AuthController::class, 'uploadPhoto']);

    // CRUD formations réservé au formateur, plus la liste de "mes formations" et
    // le téléchargement du PDF (accessible aussi aux apprenants inscrits).
    Route::post(ROUTE_FORMATIONS, [FormationController::class, 'store']);
    Route::put(ROUTE_FORMATION_BY_ID, [FormationController::class, 'update']);
    Route::delete(ROUTE_FORMATION_BY_ID, [FormationController::class, 'destroy']);
    Route::get(ROUTE_FORMATEUR_MES_FORMATIONS, [FormationController::class, 'mesFormations']);
    Route::get('/formations/{id}/pdf', [FormationController::class, 'downloadPdf']);

    // CRUD modules réservé au formateur propriétaire, plus le marquage "terminé" par l'apprenant.
    Route::post(ROUTE_FORMATION_MODULES, [ModuleController::class, 'store']);
    Route::put(ROUTE_MODULE_BY_ID, [ModuleController::class, 'update']);
    Route::delete(ROUTE_MODULE_BY_ID, [ModuleController::class, 'destroy']);
    Route::post(ROUTE_MODULE_TERMINER, [ModuleController::class, 'terminer']);
    Route::get(ROUTE_FORMATION_MODULES_TERMINES, [ModuleController::class, 'mesModulesTermines']);

    // Inscriptions des apprenants aux formations.
    Route::post(ROUTE_FORMATION_INSCRIPTION, [InscriptionController::class, 'store']);
    Route::delete(ROUTE_FORMATION_INSCRIPTION, [InscriptionController::class, 'destroy']);
    Route::get(ROUTE_APPRENANT_FORMATIONS, [InscriptionController::class, 'mesFormations']);

    // Paiement (mock simulé) avec re-authentification mot de passe.
    // Voir PaymentController + PaymentService pour les contrôles de sécurité.
    Route::post('/payments/confirmer', [PaymentController::class, 'confirmer']);

    // Messagerie 1:1 entre apprenants et formateurs.
    Route::get(ROUTE_MESSAGES_NON_LUS, [MessageController::class, 'nonLus']);
    Route::get(ROUTE_MESSAGES_CONVERSATIONS, [MessageController::class, 'conversations']);
    Route::get(ROUTE_MESSAGES_CONVERSATION, [MessageController::class, 'messagerie']);
    Route::post(ROUTE_MESSAGES_ENVOYER, [MessageController::class, 'envoyer']);
    Route::get(ROUTE_MESSAGES_INTERLOCUTEURS, [MessageController::class, 'interlocuteurs']);
});

// Lecture publique des formations et modules (pas de token requis pour explorer le catalogue).
Route::get(ROUTE_FORMATIONS, [FormationController::class, 'index']);
Route::get(ROUTE_FORMATION_BY_ID, [FormationController::class, 'show']);
Route::get(ROUTE_FORMATION_MODULES, [ModuleController::class, 'index']);

// Catch-all OPTIONS pour les requêtes préflight CORS qui n'auraient pas été interceptées
// par CorsMiddleware (sécurité supplémentaire). Les en-têtes CORS sont ajoutés par le middleware.
Route::options('/{any}', function () {
    return response('', 200);
})->where('any', '.*');
