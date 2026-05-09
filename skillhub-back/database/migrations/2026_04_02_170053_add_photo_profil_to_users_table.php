<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : ajoute la colonne photo_profil à la table users.
 *
 * Stocke le chemin relatif de l'avatar (ex. "/images/profils/profil_42_1700000000.jpg").
 * Le fichier physique est uploadé via AuthController::uploadPhoto() dans public/images/profils/.
 * NULL = pas de photo, le frontend affiche alors un avatar par défaut.
 */
return new class extends Migration
{
    /**
     * Ajoute la colonne photo_profil (string nullable) après "role".
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Chemin relatif vers la photo de profil. Concaténé avec l'host côté frontend.
            $table->string('photo_profil')->nullable()->after('role');
        });
    }

    /**
     * Supprime la colonne photo_profil (rollback).
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('photo_profil');
        });
    }
};
