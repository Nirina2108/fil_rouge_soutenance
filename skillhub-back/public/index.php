<?php

/*
 |--------------------------------------------------------------------------
 | public/index.php — Front controller HTTP de Laravel
 |--------------------------------------------------------------------------
 |
 | C'est le point d'entrée de TOUTES les requêtes web destinées à Laravel.
 | Apache (.htaccess) ou Nginx redirigent toute URL vers ce fichier, qui :
 |   1. Déclare le timestamp de démarrage (pour le profiler).
 |   2. Vérifie si le mode maintenance est actif (php artisan down).
 |   3. Charge l'autoloader Composer.
 |   4. Boot Laravel via bootstrap/app.php.
 |   5. Délègue le traitement de la requête HTTP à Application::handleRequest.
 |
 | En dev local on n'utilise PAS ce fichier directement : on lance
 | `php artisan serve` qui passe par un router PHP intégré. Mais en prod
 | (Apache/Nginx), c'est ce fichier qui est exposé à l'extérieur.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

// Capture le timestamp de démarrage pour le profiler / Telescope.
define('LARAVEL_START', microtime(true));

// Si le fichier maintenance.php existe (créé par `php artisan down`),
// on l'inclut pour répondre 503 sans booter le reste de l'app.
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Autoloader Composer : charge toutes les classes PSR-4 de l'app et de vendor/.
require __DIR__.'/../vendor/autoload.php';

// Bootstrap de Laravel : retourne une instance Application configurée.
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

// Capture la requête HTTP courante et délègue à Laravel : routage, middleware,
// contrôleur, sérialisation de la réponse et envoi au client.
$app->handleRequest(Request::capture());
