<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table inscriptions — lien apprenant <-> formation suivie.
 *
 * Stocke aussi la progression % de l'apprenant, recalculée à chaque
 * fois qu'il marque un module comme terminé (ratio modules_termines / total_modules).
 * Une ligne unique par couple (utilisateur_id, formation_id) — gérée côté contrôleur
 * via une vérification 409 Conflict (pas de contrainte UNIQUE en DB).
 */
return new class extends Migration
{
    /**
     * Crée la table inscriptions avec les deux FK et la progression.
     */
    public function up(): void
    {
        Schema::create('inscriptions', function (Blueprint $table) {
            // PK auto-incrémentée.
            $table->id();

            // FK utilisateur (l'apprenant). On déclare la colonne en deux temps
            // pour pouvoir la nommer "utilisateur_id" plutôt que "user_id" par défaut.
            $table->unsignedBigInteger('utilisateur_id');

            // FK formation suivie.
            $table->unsignedBigInteger('formation_id');

            // Progression en pourcentage (0 à 100). Initialisée à 0 lors de l'inscription.
            $table->integer('progression')->default(0);

            // created_at = date d'inscription, updated_at = dernière maj de progression.
            $table->timestamps();

            // Contraintes FK explicites (créées séparément pour pouvoir choisir le nom de colonne).
            // CASCADE : si l'utilisateur ou la formation disparaît, on supprime l'inscription.
            $table->foreign('utilisateur_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('formation_id')
                ->references('id')
                ->on('formations')
                ->onDelete('cascade');
        });
    }

    /**
     * Drop la table inscriptions (rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('inscriptions');
    }
};
