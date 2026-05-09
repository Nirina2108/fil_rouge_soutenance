<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table formation_vues — comptage des vues uniques par formation.
 *
 * Anti-doublon : on n'incrémente formations.nombre_de_vues que si la
 * combinaison (formation_id + utilisateur_id) ou (formation_id + ip)
 * n'existe pas déjà. Permet de comptabiliser les vues de manière équitable
 * (un même utilisateur qui rafraîchit la page ne fait pas grimper le compteur).
 */
return new class extends Migration
{
    /**
     * Crée la table formation_vues avec les deux index pour anti-doublon.
     */
    public function up(): void
    {
        Schema::create('formation_vues', function (Blueprint $table) {
            // PK auto-incrémentée.
            $table->id();

            // FK formation regardée. CASCADE : on purge les vues si la formation est supprimée.
            $table->foreignId('formation_id')->constrained()->onDelete('cascade');

            // FK utilisateur (NULL pour visiteurs anonymes — comptés alors via l'IP).
            $table->foreignId('utilisateur_id')->nullable()->constrained('users')->onDelete('cascade');

            // IP pour distinguer les visiteurs anonymes entre eux. NULL si utilisateur connecté.
            $table->string('ip')->nullable();
            $table->timestamps();

            // Une seule vue par utilisateur connecté par formation : empêche le re-comptage.
            $table->unique(['formation_id', 'utilisateur_id']);

            // Index pour la recherche rapide des vues anonymes par IP.
            $table->index(['formation_id', 'ip']);
        });
    }

    /**
     * Drop la table formation_vues (rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('formation_vues');
    }
};
