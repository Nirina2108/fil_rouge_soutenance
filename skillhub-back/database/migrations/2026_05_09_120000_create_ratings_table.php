<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table ratings — notes/avis donnés par les apprenants sur les formations.
 *
 * Un apprenant inscrit à une formation peut lui attribuer une note de 1 à 5
 * et un commentaire optionnel. La contrainte UNIQUE (user_id, formation_id)
 * garantit qu'un apprenant ne peut noter une même formation qu'une seule fois
 * (règle métier validée aussi côté contrôleur pour un message d'erreur explicite).
 *
 * La note moyenne et le nombre d'avis sont calculés à la volée par
 * RatingService::calculerStatistiques() et exposés via GET /api/formations/{id}.
 */
return new class extends Migration
{
    /**
     * Crée la table ratings avec ses deux FK et la contrainte d'unicité.
     */
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            // PK auto-incrémentée.
            $table->id();

            // FK utilisateur (apprenant qui note). CASCADE : si user supprimé, sa note disparaît.
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            // FK formation notée. CASCADE : si formation supprimée, ses notes disparaissent.
            $table->foreignId('formation_id')
                ->constrained('formations')
                ->onDelete('cascade');

            // Note de 1 à 5 (validation côté service + contrainte CHECK ici).
            // tinyInteger économise l'espace (1 octet vs 4 pour integer).
            $table->unsignedTinyInteger('note');

            // Commentaire libre optionnel (TEXT — jusqu'à 64 KB).
            $table->text('commentaire')->nullable();

            // created_at + updated_at.
            $table->timestamps();

            // Anti-doublon : un apprenant ne peut noter une formation qu'une seule fois.
            // Si on tente d'insérer un doublon, MySQL renvoie une erreur 1062 que
            // le service intercepte pour renvoyer 400.
            $table->unique(['user_id', 'formation_id']);
        });
    }

    /**
     * Drop la table ratings (rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
