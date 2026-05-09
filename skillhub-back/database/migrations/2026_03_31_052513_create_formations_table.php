<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table formations — entités principales du catalogue SkillHub.
 *
 * Chaque formation est créée par un formateur (FK formateur_id → users)
 * et contient ensuite des modules (leçons) et des inscriptions d'apprenants.
 * Les colonnes prix/duree/fichier_pdf sont ajoutées via des migrations ultérieures
 * (add_prix_duree_to_formations_table, add_fichier_pdf_to_formations_table).
 */
return new class extends Migration
{
    /**
     * Crée la table formations avec les champs descriptifs et le compteur de vues.
     */
    public function up(): void
    {
        Schema::create('formations', function (Blueprint $table) {
            // PK auto-incrémentée.
            $table->id();

            // Titre court affiché dans le catalogue (ex. "Initiation à React").
            $table->string('titre');

            // Description longue (TEXT — jusqu'à 64 KB) affichée sur la page détail.
            $table->text('description');

            // Catégorie pédagogique (ex. "dev-web", "data", "design"). Default "autre" pour ne pas bloquer.
            $table->string('categorie')->default('autre');

            // Niveau requis (ex. "debutant", "intermediaire", "avance").
            $table->string('niveau');

            // Compteur de vues uniques (incrémenté via la table formation_vues, jamais directement).
            $table->unsignedInteger('nombre_de_vues')->default(0);

            // FK vers users : le formateur propriétaire. ON DELETE CASCADE = supprime
            // automatiquement les formations si le formateur disparaît.
            $table->foreignId('formateur_id')->constrained('users')->onDelete('cascade');

            // created_at + updated_at (utilisés pour trier les formations "récentes").
            $table->timestamps();
        });
    }

    /**
     * Drop la table formations (rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('formations');
    }
};
