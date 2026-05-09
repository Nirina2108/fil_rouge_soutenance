<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : ajoute la colonne fichier_pdf à la table formations.
 *
 * Permet au formateur de joindre un PDF de cours (poly, slides, etc.).
 * Le fichier est uploadé dans public/storage/formations_pdf/ ; la colonne
 * stocke le chemin relatif. NULL = pas de PDF associé.
 */
return new class extends Migration
{
    /**
     * Ajoute la colonne fichier_pdf (string nullable) après duree_heures.
     */
    public function up(): void
    {
        Schema::table('formations', function (Blueprint $table) {
            // Chemin relatif vers le PDF (ex. "/storage/formations_pdf/cours_42.pdf").
            $table->string('fichier_pdf')->nullable()->after('duree_heures');
        });
    }

    /**
     * Supprime la colonne fichier_pdf (rollback).
     */
    public function down(): void
    {
        Schema::table('formations', function (Blueprint $table) {
            $table->dropColumn('fichier_pdf');
        });
    }
};
