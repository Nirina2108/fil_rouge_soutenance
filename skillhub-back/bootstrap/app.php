<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\CorsMiddleware;

/*
 |--------------------------------------------------------------------------
 | Bootstrap de l'application Laravel 11 (style "Application::configure")
 |--------------------------------------------------------------------------
 |
 | Laravel 11 a remplacé l'ancien Kernel.php / web.php / Bootstrap par un
 | unique fichier bootstrap/app.php utilisant un builder fluent. Ici on
 | déclare :
 |   - les fichiers de routes (web, api, console),
 |   - le endpoint de healthcheck (/up — utilisé par le Dockerfile),
 |   - les middlewares globaux (ici CorsMiddleware en tête de chaîne),
 |   - le handler d'exceptions personnalisé (vide pour l'instant).
 */

// Crée l'instance Application en pointant la racine du projet (un cran au-dessus de bootstrap/).
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Routes HTML (page d'accueil welcome).
        web: __DIR__ . '/../routes/web.php',
        // Routes REST de l'API SkillHub (préfixées /api automatiquement par Laravel).
        api: __DIR__ . '/../routes/api.php',
        // Commandes Artisan définies en closure (php artisan inspire, etc.).
        commands: __DIR__ . '/../routes/console.php',
        // Endpoint de healthcheck auto-généré par Laravel (utilisé par le healthcheck Docker compose).
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CorsMiddleware en TÊTE de pile (prepend) : doit s'exécuter avant tout autre middleware
        // pour pouvoir répondre aux requêtes préflight OPTIONS sans déclencher l'auth.
        $middleware->prepend(CorsMiddleware::class);

        // API JSON-only : on neutralise la redirection par defaut "vers route('login')"
        // sur les requetes non-authentifiees. Sans ca, Laravel logue une exception
        // "Route [login] not defined" sur chaque 401 (token absent/expiré).
        // En retournant null ici, Authenticate->redirectTo() court-circuite la
        // redirection et le middleware emet directement une 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API : on convertit AuthenticationException en 401 JSON propre, sans
        // tentative de redirection vers une route web qui n'existe pas.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            return response()->json(['message' => 'Non authentifié'], 401);
        });
    })
    ->create();
