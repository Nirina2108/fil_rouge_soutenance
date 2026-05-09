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

// Route GET / : page d'accueil minimale de l'API.
// On evite la vue welcome.blade.php par defaut (qui appelle route('login')
// et route('register') inexistantes -> 500 sur l'endpoint racine).
Route::get('/', function () {
    return response('SkillHub API ' . config('app.name') . ' - voir /api', 200)
        ->header('Content-Type', 'text/plain; charset=utf-8');
});
