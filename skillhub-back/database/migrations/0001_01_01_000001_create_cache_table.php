<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : tables techniques du driver de cache "database" Laravel.
 *
 * Utilisé quand CACHE_STORE=database (.env). Permet de mettre Laravel
 * en cache sans Redis/Memcached. Deux tables :
 *  - cache       : entrées key/value avec date d'expiration.
 *  - cache_locks : verrous distribués (atomic locks) gérés par Laravel.
 */
return new class extends Migration
{
    /**
     * Crée les deux tables nécessaires au store cache "database".
     */
    public function up(): void
    {
        // Table principale : stocke les entrées de cache (clé → valeur sérialisée).
        Schema::create('cache', function (Blueprint $table) {
            // Clé du cache (ex. "user:42:profile") — primary key.
            $table->string('key')->primary();

            // Valeur sérialisée (jusqu'à ~16 MB grâce à mediumText).
            $table->mediumText('value');

            // Timestamp UNIX d'expiration. Indexé pour le purge périodique.
            $table->integer('expiration')->index();
        });

        // Table de verrous : permet à Laravel d'utiliser Cache::lock() de façon atomique.
        Schema::create('cache_locks', function (Blueprint $table) {
            // Nom du verrou.
            $table->string('key')->primary();

            // Identifiant du process qui détient le verrou (pour autorisation de release).
            $table->string('owner');

            // Timestamp UNIX d'expiration : libération automatique si le détenteur crash.
            $table->integer('expiration')->index();
        });
    }

    /**
     * Drop les deux tables.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
