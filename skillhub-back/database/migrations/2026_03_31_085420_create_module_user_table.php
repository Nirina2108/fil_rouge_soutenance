<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table pivot module_user — modules marqués "terminés" par un apprenant.
 *
 * Tracke quels modules un apprenant a coché comme suivis. Sert à calculer
 * la progression dans la table inscriptions (% = modules cochés / total modules).
 * Contrainte UNIQUE (utilisateur_id, module_id) : un apprenant ne peut cocher
 * un même module qu'une seule fois.
 */
return new class extends Migration
{
    /**
     * Crée la table pivot avec les deux FK et le drapeau "termine".
     */
    public function up(): void
    {
        Schema::create('module_user', function (Blueprint $table) {
            // PK auto-incrémentée (alternative à une PK composite — plus simple à utiliser).
            $table->id();

            // FK vers users (l'apprenant qui a coché).
            $table->unsignedBigInteger('utilisateur_id');

            // FK vers modules (le module concerné).
            $table->unsignedBigInteger('module_id');

            // Drapeau "module terminé". Default true car on n'insère une ligne
            // que pour signifier "fait" — l'absence de ligne = "non fait".
            $table->boolean('termine')->default(true);

            // created_at = date où l'apprenant a coché, updated_at = dernière modification.
            $table->timestamps();

            // FK explicites avec CASCADE : si user/module disparaît, on supprime le suivi.
            $table->foreign('utilisateur_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('module_id')
                ->references('id')
                ->on('modules')
                ->onDelete('cascade');

            // Empêche les doublons : un apprenant ne peut cocher un module qu'une fois.
            $table->unique(['utilisateur_id', 'module_id']);
        });
    }

    /**
     * Drop la table pivot (rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('module_user');
    }
};
