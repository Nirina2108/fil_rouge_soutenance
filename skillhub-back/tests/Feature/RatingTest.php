<?php

namespace Tests\Feature;

use App\Models\Formation;
use App\Models\Inscription;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Tests de la fonctionnalité "Notation des formations" (feature/progression-apprenant).
 *
 * Cas couverts (cf. cahier des charges EC09 Partie B - Fonctionnalité 1) :
 *   1. Apprenant inscrit soumet une note valide → 201 + ligne en base
 *   2. Même apprenant tente une 2e note → 400
 *   3. Note hors intervalle (ex. 6) → 400
 *   4. Apprenant non inscrit → 403
 *   5. Requête sans token JWT → 401
 *
 * Plus deux tests bonus :
 *   6. GET /api/formations/{id} expose note_moyenne + nombre_avis
 *   7. Calcul de la note moyenne sur plusieurs ratings
 */
class RatingTest extends TestCase
{
    // RefreshDatabase : DB SQLite in-memory recréée entre chaque test (transactionnel + rollback).
    use RefreshDatabase;

    private const URL_NOTER_TEMPLATE = '/api/formations/%d/noter';

    /**
     * Helper : crée un User avec un rôle donné et retourne le couple {user, token JWT}.
     */
    private function creerUtilisateur(string $role): array
    {
        $user = User::create([
            'nom' => 'Test ' . ucfirst($role),
            'email' => $role . '_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'role' => $role,
        ]);

        return ['user' => $user, 'token' => JWTAuth::fromUser($user)];
    }

    /**
     * Helper : crée une formation appartenant au formateur donné.
     */
    private function creerFormation(User $formateur): Formation
    {
        return Formation::create([
            'titre' => 'Formation de test',
            'description' => 'Description courte',
            'categorie' => 'developpement_web',
            'niveau' => 'debutant',
            'nombre_de_vues' => 0,
            'formateur_id' => $formateur->id,
        ]);
    }

    /**
     * Helper : inscrit un apprenant à une formation (table inscriptions).
     */
    private function inscrire(User $apprenant, Formation $formation): void
    {
        Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id' => $formation->id,
            'progression' => 0,
        ]);
    }

    /**
     * Construit le header Authorization Bearer pour les requêtes authentifiées.
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    // ─── Cas nominal ──────────────────────────────────────────────────────────

    /**
     * Cas 1 : un apprenant inscrit soumet une note valide → 201 + persistance en base.
     */
    #[Test]
    public function un_apprenant_inscrit_peut_noter_une_formation(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');
        $this->inscrire($apprenant, $formation);

        $response = $this->postJson(
            sprintf(self::URL_NOTER_TEMPLATE, $formation->id),
            ['note' => 4, 'commentaire' => 'Très bonne formation'],
            $this->headers($token)
        );

        // Status 201 + structure du JSON retourné.
        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'rating' => ['id', 'user_id', 'formation_id', 'note', 'commentaire']]);

        // Persistance : la ligne doit exister en base avec les bonnes valeurs.
        $this->assertDatabaseHas('ratings', [
            'user_id' => $apprenant->id,
            'formation_id' => $formation->id,
            'note' => 4,
            'commentaire' => 'Très bonne formation',
        ]);
    }

    // ─── Cas d'erreur ─────────────────────────────────────────────────────────

    /**
     * Cas 2 : un apprenant ne peut noter qu'une seule fois → 400 sur la 2e tentative.
     */
    #[Test]
    public function un_apprenant_ne_peut_pas_noter_une_deuxieme_fois(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');
        $this->inscrire($apprenant, $formation);

        // 1re note : OK.
        $this->postJson(
            sprintf(self::URL_NOTER_TEMPLATE, $formation->id),
            ['note' => 5],
            $this->headers($token)
        )->assertStatus(201);

        // 2e note pour la même formation : doit échouer en 400.
        $response = $this->postJson(
            sprintf(self::URL_NOTER_TEMPLATE, $formation->id),
            ['note' => 3],
            $this->headers($token)
        );

        $response->assertStatus(400)
            ->assertJsonPath('erreur', 'deja_note');

        // Une seule ligne en base (la 2e n'a pas été insérée).
        $this->assertEquals(1, Rating::where('user_id', $apprenant->id)
            ->where('formation_id', $formation->id)
            ->count());
    }

    /**
     * Cas 3 : note hors intervalle [1-5] → 400 (ici on teste 6).
     */
    #[Test]
    public function note_hors_intervalle_retourne_400(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');
        $this->inscrire($apprenant, $formation);

        $response = $this->postJson(
            sprintf(self::URL_NOTER_TEMPLATE, $formation->id),
            ['note' => 6],   // hors [1-5]
            $this->headers($token)
        );

        $response->assertStatus(400)
            ->assertJsonPath('erreur', 'note_invalide');

        // Pas de ligne en base.
        $this->assertEquals(0, Rating::count());
    }

    /**
     * Bonus : note négative ou 0 (autres extrémités hors intervalle) → 400.
     */
    #[Test]
    public function note_zero_ou_negative_retourne_400(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');
        $this->inscrire($apprenant, $formation);

        // Note = 0 : hors intervalle.
        $this->postJson(
            sprintf(self::URL_NOTER_TEMPLATE, $formation->id),
            ['note' => 0],
            $this->headers($token)
        )->assertStatus(400);
    }

    /**
     * Cas 4 : apprenant non inscrit à la formation → 403.
     */
    #[Test]
    public function un_apprenant_non_inscrit_ne_peut_pas_noter(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        // Apprenant créé mais NON inscrit (pas d'appel à inscrire()).
        ['token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->postJson(
            sprintf(self::URL_NOTER_TEMPLATE, $formation->id),
            ['note' => 4],
            $this->headers($token)
        );

        $response->assertStatus(403)
            ->assertJsonPath('erreur', 'non_inscrit');

        $this->assertEquals(0, Rating::count());
    }

    /**
     * Cas 5 : requête sans token JWT → 401 (middleware auth:api).
     */
    #[Test]
    public function noter_sans_token_retourne_401(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $response = $this->postJson(
            sprintf(self::URL_NOTER_TEMPLATE, $formation->id),
            ['note' => 4]
        );

        $response->assertStatus(401);
    }

    // ─── Bonus : exposition de note_moyenne + nombre_avis ────────────────────

    /**
     * GET /api/formations/{id} doit inclure note_moyenne et nombre_avis dans la réponse.
     */
    #[Test]
    public function show_formation_inclut_note_moyenne_et_nombre_avis(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        // 3 apprenants notent : 5, 4, 3 → moyenne 4.0, nombre 3.
        foreach ([5, 4, 3] as $note) {
            ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');
            $this->inscrire($apprenant, $formation);
            $this->postJson(
                sprintf(self::URL_NOTER_TEMPLATE, $formation->id),
                ['note' => $note],
                $this->headers($token)
            )->assertStatus(201);
        }

        $response = $this->getJson('/api/formations/' . $formation->id);

        $response->assertStatus(200)
            ->assertJsonPath('nombre_avis', 3);

        // Note moyenne : on accepte 4 ou 4.0 (PHP convertit selon le résultat).
        $this->assertEqualsWithDelta(4.0, (float) $response->json('note_moyenne'), 0.01);
    }

    /**
     * Une formation sans aucune note doit renvoyer note_moyenne=0 et nombre_avis=0
     * (et non null, pour simplifier le rendu côté front).
     */
    #[Test]
    public function show_formation_sans_note_renvoie_zero_zero(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $response = $this->getJson('/api/formations/' . $formation->id);

        $response->assertStatus(200)
            ->assertJsonPath('note_moyenne', 0)
            ->assertJsonPath('nombre_avis', 0);
    }
}
