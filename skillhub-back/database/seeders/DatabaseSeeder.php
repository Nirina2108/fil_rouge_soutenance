<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeder principal — point d'entrée de `php artisan db:seed`.
 *
 * Pour SkillHub, on n'utilise pas de seeder en production : la base est
 * peuplée par les vrais utilisateurs via /register. Ce seeder ne sert qu'au
 * développement local pour avoir un compte de test rapidement.
 *
 * Pour ajouter d'autres seeders (formations, modules, etc.) : créer une
 * classe Database\Seeders\NomSeeder puis appeler $this->call(NomSeeder::class).
 */
class DatabaseSeeder extends Seeder
{
    // Empêche les events Eloquent (created, updated, etc.) pendant le seed.
    // Évite des side-effects type ActivityLog quand on bourre la DB.
    use WithoutModelEvents;

    /**
     * Crée les données par défaut de la base.
     *
     * Note : ce code utilise les colonnes "name" / "email_verified_at" alignées
     * sur le UserFactory Laravel par défaut, et NON sur le schéma SkillHub
     * (qui utilise "nom" et n'a pas de email_verified_at). Si on veut vraiment
     * seeder un user SkillHub, il faut adapter UserFactory.
     */
    public function run(): void
    {
        // Exemple commenté : créer 10 utilisateurs aléatoires.
        // User::factory(10)->create();

        // Crée un utilisateur de test fixe.
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
