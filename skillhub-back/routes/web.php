<?php

use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 | Routes web — interface HTML servie par Laravel
 |--------------------------------------------------------------------------
 |
 | SkillHub est une SPA React : le frontend gère toutes les pages côté
 | navigateur. Le backend Laravel n'expose qu'une API REST (voir routes/api.php).
 | Cette route racine sert juste la vue d'accueil par défaut de Laravel,
 | utile pour vérifier que l'app PHP démarre correctement (ex. lors du
 | déploiement). Le healthcheck Docker tape sur /up (route auto Laravel 11).
 */

// Route GET / : retourne la vue welcome.blade.php (page Laravel par défaut).
Route::get('/', function () {
    return view('welcome');
});
