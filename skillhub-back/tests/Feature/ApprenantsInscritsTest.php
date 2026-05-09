<?php

namespace Tests\Feature;

use App\Models\Formation;
use App\Models\Inscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Tests de la fonctionnalité "Liste des apprenants inscrits" (feature/liste-apprenants).
 *
 * Cas couverts (cf. cahier des charges EC09 Partie B - Fonctionnalité 2) :
 *   1. Formateur propriétaire → 200 + structure JSON attendue
 *   2. Formateur non propriétaire → 403
 *   3. Formation sans apprenants → 200 + tableau vide
 *   4. Requête sans token JWT → 401
 *
 * Plus :
 *   5. Formation introuvable → 404 (cas implicite mais mérite un test explicite)
 *   6. Apprenant connecté tente d'accéder → 403 (mauvais rôle)
 */
class ApprenantsInscritsTest extends TestCase
{
    // RefreshDatabase : DB SQLite in-memory recréée entre chaque test.
    use RefreshDatabase;

    /**
     * Helper : crée un utilisateur du rôle demandé et retourne {user, token JWT}.
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
     * Helper : inscrit un apprenant à une formation avec une progression donnée.
     */
    private function inscrire(User $apprenant, Formation $formation, int $progression = 0): Inscription
    {
        return Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id' => $formation->id,
            'progression' => $progression,
        ]);
    }

    /**
     * Construit le header Authorization Bearer.
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Construit l'URL de l'endpoint pour une formation donnée.
     */
    private function url(int $formationId): string
    {
        return '/api/formations/' . $formationId . '/apprenants';
    }

    // ─── Cas nominal ──────────────────────────────────────────────────────────

    /**
     * Cas 1 : formateur propriétaire avec apprenants inscrits → 200 + structure JSON correcte.
     */
    #[Test]
    public function formateur_proprietaire_recoit_la_liste_des_apprenants_inscrits(): void
    {
        ['user' => $formateur, 'token' => $tokenFormateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        // 2 apprenants avec progression différente.
        ['user' => $a1] = $this->creerUtilisateur('apprenant');
        ['user' => $a2] = $this->creerUtilisateur('apprenant');
        $this->inscrire($a1, $formation, 25);
        $this->inscrire($a2, $formation, 100);

        $response = $this->getJson($this->url($formation->id), $this->headers($tokenFormateur));

        // 200 + clé "apprenants" + array de 2 éléments.
        $response->assertStatus(200)
            ->assertJsonCount(2, 'apprenants')
            ->assertJsonStructure([
                'apprenants' => [
                    '*' => ['id', 'nom', 'email', 'progression', 'date_inscription'],
                ],
            ]);

        // Vérifie au moins un apprenant + sa progression.
        $apprenants = $response->json('apprenants');
        $this->assertEquals($a1->id, $apprenants[0]['id']);
        $this->assertEquals(25, $apprenants[0]['progression']);
        $this->assertEquals($a2->email, $apprenants[1]['email']);
        $this->assertEquals(100, $apprenants[1]['progression']);
    }

    // ─── Cas vide ─────────────────────────────────────────────────────────────

    /**
     * Cas 3 : formation sans aucun apprenant inscrit → 200 + tableau vide.
     * (Pas une erreur : c'est un état valide, juste vide.)
     */
    #[Test]
    public function formation_sans_apprenants_renvoie_tableau_vide(): void
    {
        ['user' => $formateur, 'token' => $tokenFormateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $response = $this->getJson($this->url($formation->id), $this->headers($tokenFormateur));

        $response->assertStatus(200)
            ->assertJsonCount(0, 'apprenants');
    }

    // ─── Cas d'erreur ─────────────────────────────────────────────────────────

    /**
     * Cas 2 : un autre formateur (non propriétaire) → 403.
     * Sécurité : un formateur ne doit pas pouvoir voir les apprenants d'une
     * formation qui ne lui appartient pas (RGPD côté apprenants).
     */
    #[Test]
    public function un_autre_formateur_recoit_403(): void
    {
        ['user' => $proprietaire] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($proprietaire);

        // Apprenant inscrit (pour qu'il y ait quelque chose à protéger).
        ['user' => $apprenant] = $this->creerUtilisateur('apprenant');
        $this->inscrire($apprenant, $formation);

        // Un AUTRE formateur tente d'accéder à la liste.
        ['token' => $tokenIntrus] = $this->creerUtilisateur('formateur');

        $response = $this->getJson($this->url($formation->id), $this->headers($tokenIntrus));

        $response->assertStatus(403)
            ->assertJsonPath('erreur', 'non_proprietaire');
    }

    /**
     * Cas 4 : requête sans token JWT → 401 (middleware auth:api).
     */
    #[Test]
    public function requete_sans_token_recoit_401(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $response = $this->getJson($this->url($formation->id));

        $response->assertStatus(401);
    }

    /**
     * Cas 5 (bonus) : formation inexistante → 404 (avant même la vérif de propriété).
     */
    #[Test]
    public function formation_inexistante_recoit_404(): void
    {
        ['token' => $tokenFormateur] = $this->creerUtilisateur('formateur');

        $response = $this->getJson($this->url(99999), $this->headers($tokenFormateur));

        $response->assertStatus(404)
            ->assertJsonPath('erreur', 'formation_introuvable');
    }

    /**
     * Cas 6 (bonus) : un apprenant authentifié n'est pas propriétaire (par essence).
     * Doit renvoyer 403, pas 200, même si techniquement il s'agit d'un user inscrit.
     */
    #[Test]
    public function apprenant_meme_inscrit_recoit_403(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        // L'apprenant est inscrit à la formation, mais ce n'est pas le propriétaire.
        ['user' => $apprenant, 'token' => $tokenApprenant] = $this->creerUtilisateur('apprenant');
        $this->inscrire($apprenant, $formation);

        $response = $this->getJson($this->url($formation->id), $this->headers($tokenApprenant));

        $response->assertStatus(403);
    }
}
