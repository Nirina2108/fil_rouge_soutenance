<?php

use App\Providers\AppServiceProvider;

/*
 |--------------------------------------------------------------------------
 | Providers de l'application
 |--------------------------------------------------------------------------
 |
 | Liste des Service Providers personnalisés à enregistrer au boot.
 | Les providers de paquets tiers (jwt-auth, etc.) sont auto-découverts
 | via composer.json (extra.laravel.providers) — pas besoin de les lister ici.
 |
 | Pour ajouter un provider personnalisé : créer la classe dans app/Providers/
 | puis ajouter App\Providers\NomProvider::class dans le tableau ci-dessous.
 */

return [
    // Provider principal de l'app : extension Mongo, log custom, bindings, etc.
    AppServiceProvider::class,
];
