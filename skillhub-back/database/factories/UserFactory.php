<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Factory de génération d'utilisateurs fictifs (Faker).
 *
 * Utilisée par les tests Feature pour créer rapidement un user en DB :
 *   $user = User::factory()->create();
 *
 * @extends Factory<User>
 *
 * Note : les champs "name" / "email_verified_at" suivent la convention Laravel
 * par défaut. Le schéma réel SkillHub utilise "nom" (sans le "m") et n'a pas
 * email_verified_at. À adapter si on veut tester le flux d'inscription métier.
 */
class UserFactory extends Factory
{
    /**
     * Mot de passe partagé par tous les users créés via cette factory.
     * Hashé une seule fois (via le coalesce ??=) pour accélérer les tests.
     */
    protected static ?string $password;

    /**
     * Retourne l'état par défaut d'un User factice.
     *
     * Faker (`fake()`) génère des valeurs aléatoires plausibles : nom,
     * email unique au sein de la suite de tests, etc.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Nom factice (ex. "Marie Dupont").
            'name' => fake()->name(),

            // Email unique (Faker garantit l'unicité dans la même suite).
            'email' => fake()->unique()->safeEmail(),

            // Marque l'email comme vérifié (now() = timestamp courant).
            'email_verified_at' => now(),

            // Hash bcrypt du mot de passe "password" — partagé entre tous les users.
            'password' => static::$password ??= Hash::make('password'),

            // Token "remember me" aléatoire (10 caractères).
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * État alternatif : utilisateur dont l'email n'a pas été vérifié.
     *
     * Usage : User::factory()->unverified()->create();
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            // Surcharge la valeur par défaut "now()" par null.
            'email_verified_at' => null,
        ]);
    }
}
