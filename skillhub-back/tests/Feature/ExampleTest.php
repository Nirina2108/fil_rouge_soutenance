<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test Feature généré par défaut par Laravel.
 *
 * Vérifie simplement que la route racine répond bien en HTTP 200.
 * Sert de smoke test minimal pour s'assurer que l'application boote.
 */
class ExampleTest extends TestCase
{
    /**
     * Vérifie que GET / retourne un 200 (vue welcome.blade.php).
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // Effectue une requête HTTP GET sur la racine.
        $response = $this->get('/');

        // Vérifie le code de statut HTTP attendu.
        $response->assertStatus(200);
    }
}
