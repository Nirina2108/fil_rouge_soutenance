<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table modules — leçons/chapitres composant une formation.
 *
 * Une formation a 1..N modules. Chaque apprenant inscrit peut marquer
 * un module comme "terminé" (via la table pivot module_user créée juste après).
 * Le champ "ordre" permet l'affichage dans l'ordre pédagogique défini par le formateur.
 */
return new class extends Migration
{
    /**
     * Crée la table modules avec lien vers la formation parente.
     */
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            // PK auto-incrémentée.
            $table->id();

            // Titre du module (ex. "Chapitre 1 : Les composants").
            $table->string('titre');

            // Contenu pédagogique (TEXT — peut contenir Markdown ou HTML léger).
            $table->text('contenu');

            // Ordre d'affichage du module dans la formation (1, 2, 3, …).
            // Utilisé par Formation::modules() avec orderBy('ordre').
            $table->integer('ordre');

            // FK vers formations. ON DELETE CASCADE = supprimer une formation
            // supprime automatiquement tous ses modules.
            $table->foreignId('formation_id')
                ->constrained('formations')
                ->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Drop la table modules (rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
