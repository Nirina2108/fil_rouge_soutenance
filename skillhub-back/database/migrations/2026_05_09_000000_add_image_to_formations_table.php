<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : ajoute la colonne image à la table formations.
 *
 * Permet au formateur d'illustrer sa formation avec une image (jpeg, png, webp).
 * Stockée dans storage/app/public/formations/{id}/image.{ext} et accessible via
 * URL publique /storage/formations/{id}/image.{ext} grâce au symlink storage:link.
 *
 * NULL = pas d'image : le frontend affiche un placeholder CSS (pas d'image
 * par défaut côté backend pour rester sans ressource statique).
 */
return new class extends Migration
{
    /**
     * Ajoute la colonne image (string nullable) après fichier_pdf.
     */
    public function up(): void
    {
        Schema::table('formations', function (Blueprint $table) {
            // Chemin relatif vers l'image stockée (ex. "formations/42/image.jpg").
            // Le frontend construit l'URL complète via http://host/storage/{$image}.
            $table->string('image')->nullable()->after('fichier_pdf');
        });
    }

    /**
     * Supprime la colonne image (rollback).
     */
    public function down(): void
    {
        Schema::table('formations', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
