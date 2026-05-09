<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Service Provider principal de SkillHub.
 *
 * Point d'extension pour brancher des services / bindings / observers
 * personnalisés au boot de l'application. Sur SkillHub, on ne configure
 * rien d'inhabituel ici : tous les paquets tiers (jwt-auth, mongodb)
 * s'auto-enregistrent via leurs propres providers déclarés dans
 * vendor/.../composer.json (auto-discovery).
 *
 * Si on devait ajouter :
 *   - un binding d'interface vers une implémentation : dans register()
 *   - un Eloquent observer / event listener / route model binding : dans boot()
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Enregistre les bindings dans le container Laravel.
     * Appelée tôt dans le cycle de boot, AVANT que d'autres providers ne soient bootés.
     * À utiliser uniquement pour des bindings simples (ne JAMAIS résoudre des services ici).
     */
    public function register(): void
    {
        //
    }

    /**
     * Initialise les services applicatifs.
     * Appelée après que tous les providers ont été enregistrés.
     * À utiliser pour : enregistrer des observers, des macros, des règles de validation, etc.
     */
    public function boot(): void
    {
        //
    }
}
