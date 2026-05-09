<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table users — utilisateurs de SkillHub (apprenants ET formateurs).
 *
 * C'est la table racine de l'authentification : toutes les FK
 * `utilisateur_id`, `expediteur_id`, `formateur_id`, etc. pointent ici.
 */
return new class extends Migration
{
    /**
     * Crée la table users avec les colonnes minimales nécessaires
     * à l'inscription / connexion JWT.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // Clé primaire auto-incrémentée (BIGINT UNSIGNED).
            $table->id();

            // Nom complet affiché (ex. "Jean Dupont").
            $table->string('nom');

            // Email = identifiant unique de connexion (contrainte UNIQUE en DB).
            $table->string('email')->unique();

            // Mot de passe hashé via bcrypt (jamais stocké en clair).
            $table->string('password');

            // Rôle métier : enum strict pour interdire toute autre valeur.
            // Détermine le dashboard et les permissions côté contrôleurs.
            $table->enum('role', ['apprenant', 'formateur']);

            // Token "remember me" Laravel — non utilisé en JWT mais requis par le trait Authenticatable.
            $table->rememberToken();

            // created_at + updated_at gérés automatiquement par Eloquent.
            $table->timestamps();
        });
    }

    /**
     * Drop la table users (rollback). Cascade sur toutes les tables référençant users.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
