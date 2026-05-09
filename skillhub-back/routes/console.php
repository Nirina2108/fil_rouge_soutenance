<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
 |--------------------------------------------------------------------------
 | Commandes Artisan personnalisées (closure-based)
 |--------------------------------------------------------------------------
 |
 | Permet de définir des commandes Artisan rapides sans créer de classe
 | dans app/Console/Commands. Pour des commandes métier complexes (ex.
 | calcul de progression, purge de tokens), préférer une classe dédiée.
 |
 | Liste toutes les commandes disponibles : `php artisan list`.
 */

// Commande "php artisan inspire" : affiche une citation aléatoire dans le terminal.
// Sert de smoke-test pratique — si la commande répond, l'app Laravel est bien bootstrapée.
Artisan::command('inspire', function () {
    // $this dans une closure de commande = instance du Command (méthodes comment, info, error, …).
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
