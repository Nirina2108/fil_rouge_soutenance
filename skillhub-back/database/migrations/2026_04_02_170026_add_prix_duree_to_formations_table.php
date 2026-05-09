<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : ajoute les colonnes prix et duree_heures à la table formations.
 *
 * Permet au formateur de fixer le tarif (gratuit ou payant) et la durée
 * estimée de sa formation. Affichage côté catalogue / page détail.
 */
return new class extends Migration
{
    /**
     * Ajoute les colonnes prix (DECIMAL) et duree_heures (INT) après "niveau".
     */
    public function up(): void
    {
        Schema::table('formations', function (Blueprint $table) {
            // Prix en euros — DECIMAL(8,2) = jusqu'à 999 999,99 €. 0 = formation gratuite.
            $table->decimal('prix', 8, 2)->default(0)->after('niveau');

            // Durée totale estimée de la formation en heures (entier). 0 = non précisé.
            $table->integer('duree_heures')->default(0)->after('prix');
        });
    }

    /**
     * Supprime les deux colonnes (rollback).
     */
    public function down(): void
    {
        Schema::table('formations', function (Blueprint $table) {
            $table->dropColumn(['prix', 'duree_heures']);
        });
    }
};
