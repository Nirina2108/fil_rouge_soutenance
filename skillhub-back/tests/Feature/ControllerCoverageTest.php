<?php

namespace Tests\Feature;

use App\Models\Formation;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Tests de couverture des contrôleurs : vise les chemins d'erreur (401, 403, 404)
 * et les cas absents pour augmenter le coverage Sonar/JaCoCo.
 *
 * Le scénario nominal "happy path" est testé dans SkillHubTest.php.
 * Ici on s'assure que les gardes de sécurité (token absent, ressource absente,
 * mauvais rôle) renvoient bien les codes HTTP attendus.
 */
class ControllerCoverageTest extends TestCase
{
    // RefreshDatabase : recrée la DB de test entre chaque test (transactionnel + rollback).
    use RefreshDatabase;

    /**
     * Construit l'en-tête Authorization Bearer à passer aux requêtes HTTP authentifiées.
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Crée un utilisateur du rôle demandé et retourne le couple {user, token JWT}.
     *
     * uniqid() garantit l'unicité de l'email entre tests (la contrainte UNIQUE
     * sur users.email ferait échouer un deuxième user avec le même email).
     */
    private function creerUtilisateur(string $role): array
    {
        $user = User::create([
            'nom' => 'Test ' . ucfirst($role),
            'email' => $role . '_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'role' => $role,
        ]);

        // JWTAuth::fromUser génère un token sans passer par le flow login (raccourci de test).
        return ['user' => $user, 'token' => JWTAuth::fromUser($user)];
    }

    /**
     * Crée une formation appartenant au formateur passé en argument.
     */
    private function creerFormation(User $formateur): Formation
    {
        return Formation::create([
            'titre' => 'Formation Test',
            'description' => 'Description de test',
            'categorie' => 'developpement_web',
            'niveau' => 'debutant',
            'nombre_de_vues' => 0,
            'formateur_id' => $formateur->id,
        ]);
    }

    /**
     * Crée un module rattaché à une formation, avec un ordre d'affichage.
     */
    private function creerModule(Formation $formation, int $ordre = 1): Module
    {
        return Module::create([
            'titre' => 'Module ' . $ordre,
            'contenu' => 'Contenu du module ' . $ordre,
            'ordre' => $ordre,
            'formation_id' => $formation->id,
        ]);
    }

    /**
     * Vérifie que les endpoints protégés d'auth renvoient 401 sans token.
     */
    #[Test]
    public function auth_couvre_les_cas_absents(): void
    {
        // withoutMiddleware désactive les middlewares globaux pour pouvoir tester les guards
        // de chaque méthode séparément (sans CORS qui réécrit les headers).
        $this->withoutMiddleware();

        // POST /logout sans token JWT → 401.
        $this->postJson('/api/logout', [])
            ->assertStatus(401);

        // GET /profile sans token → 401.
        $this->getJson('/api/profile')
            ->assertStatus(401);

        // POST /profil/photo sans token → 401, même avec un fichier valide.
        $file = UploadedFile::fake()->create('photo.jpg', 10, 'image/jpeg');
        $this->post('/api/profil/photo', ['photo' => $file])
            ->assertStatus(401);
    }

    /**
     * Couverture des cas d'erreur de FormationController :
     *   - sans token : 401
     *   - formation inexistante : 404
     *   - happy path mes-formations : 200.
     */
    #[Test]
    public function formations_couvrent_les_cas_absents_et_sans_token(): void
    {
        $this->withoutMiddleware();

        // 401 sans token sur les routes formateur protégées.
        $this->getJson('/api/formateur/mes-formations')
            ->assertStatus(401);

        ['user' => $formateur, 'token' => $token] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        // 404 sur formation inexistante (id 9999).
        $this->getJson('/api/formations/9999', $this->headers($token))
            ->assertStatus(404);

        // Création de formation sans token → 401.
        $this->postJson('/api/formations', [
            'titre' => 'Sans token',
        ])
            ->assertStatus(401);

        // POST module sur formation inexistante → 404.
        $this->postJson('/api/formations/9999/modules', [
            'titre' => 'Module',
            'contenu' => 'Contenu',
            'ordre' => 1,
        ], $this->headers($token))
            ->assertStatus(404);

        // PUT formation inexistante → 404.
        $this->putJson('/api/formations/9999', [
            'titre' => 'Titre',
            'description' => 'Desc',
            'categorie' => 'developpement_web',
            'niveau' => 'debutant',
        ], $this->headers($token))
            ->assertStatus(404);

        // DELETE formation inexistante → 404.
        $this->deleteJson('/api/formations/9999', [], $this->headers($token))
            ->assertStatus(404);

        // Happy path : formateur authentifié peut lister ses formations → 200.
        $this->getJson('/api/formateur/mes-formations', $this->headers($token))
            ->assertStatus(200);
    }

    /**
     * Couverture des cas d'erreur de InscriptionController.
     */
    #[Test]
    public function inscriptions_couvrent_les_cas_absents_et_sans_token(): void
    {
        $this->withoutMiddleware();

        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        // POST inscription sans token → 401.
        $this->postJson('/api/formations/' . $formation->id . '/inscription', [])
            ->assertStatus(401);

        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');

        // POST inscription sur formation inexistante → 404.
        $this->postJson('/api/formations/9999/inscription', [], $this->headers($token))
            ->assertStatus(404);

        // DELETE inscription sans token → 401.
        $this->deleteJson('/api/formations/' . $formation->id . '/inscription')
            ->assertStatus(401);

        // DELETE inscription sur formation inexistante → 404.
        $this->deleteJson('/api/formations/9999/inscription', [], $this->headers($token))
            ->assertStatus(404);

        // Un formateur ne peut pas accéder à /apprenant/formations → 403.
        ['user' => $formateur2, 'token' => $token2] = $this->creerUtilisateur('formateur');
        $this->getJson('/api/apprenant/formations', $this->headers($token2))
            ->assertStatus(403);
    }

    /**
     * Couverture des cas d'erreur de ModuleController :
     *   - 401 sans token
     *   - 403 si le formateur n'est pas le propriétaire / si rôle inadéquat
     *   - 404 si module ou formation inexistant
     *   - 200 sur happy path apprenant.
     */
    #[Test]
    public function modules_couvrent_les_cas_absents_et_sans_token(): void
    {
        $this->withoutMiddleware();

        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);
        $module = $this->creerModule($formation);

        // POST module sans token → 401.
        $this->postJson('/api/formations/' . $formation->id . '/modules', [])
            ->assertStatus(401);

        ['user' => $formateur2, 'token' => $token2] = $this->creerUtilisateur('formateur');

        // POST module dans formation inexistante (avec token valide) → 404.
        $this->postJson('/api/formations/9999/modules', [
            'titre' => 'Module',
            'contenu' => 'Contenu',
            'ordre' => 1,
        ], $this->headers($token2))
            ->assertStatus(404);

        // PUT module par un formateur qui n'en est pas propriétaire → 403.
        $this->putJson('/api/modules/' . $module->id, [
            'titre' => 'Module modifié',
            'contenu' => 'Contenu modifié',
            'ordre' => 1,
        ], $this->headers($token2))
            ->assertStatus(403);

        // DELETE module sans token → 401.
        $this->deleteJson('/api/modules/' . $module->id)
            ->assertStatus(401);

        // DELETE module inexistant → 404.
        $this->deleteJson('/api/modules/9999', [], $this->headers($token2))
            ->assertStatus(404);

        ['user' => $apprenant, 'token' => $tokenApprenant] = $this->creerUtilisateur('apprenant');

        // Apprenant peut lister ses modules terminés → 200 + structure JSON attendue.
        $this->getJson('/api/formations/' . $formation->id . '/modules-termines', $this->headers($tokenApprenant))
            ->assertStatus(200)
            ->assertJsonStructure(['modules_termines']);

        // Un formateur ne peut pas demander la liste de "ses" modules termines → 403.
        $this->getJson('/api/formations/' . $formation->id . '/modules-termines', $this->headers($token2))
            ->assertStatus(403);

        // POST terminer par un formateur (mauvais rôle) → 403.
        $this->postJson('/api/modules/' . $module->id . '/terminer', [], $this->headers($token2))
            ->assertStatus(403);

        // POST terminer par un apprenant non inscrit à la formation → 403.
        $this->postJson('/api/modules/' . $module->id . '/terminer', [], $this->headers($tokenApprenant))
            ->assertStatus(403);

        // POST terminer sur module inexistant → 404.
        $this->postJson('/api/modules/9999/terminer', [], $this->headers($tokenApprenant))
            ->assertStatus(404);

        // POST terminer sans token → 401.
        $this->postJson('/api/modules/' . $module->id . '/terminer', [])
            ->assertStatus(401);
    }
}
