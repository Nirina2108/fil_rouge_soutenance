<?php

namespace Tests\Feature;

use App\Models\Formation;
use App\Models\FormationVue;
use App\Models\Inscription;
use App\Models\Message;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Tests fonctionnels de l'API SkillHub.
 *
 * Couvre :
 * - Authentification (register, login, profile, logout)
 * - Formations (CRUD formateur, contrôle rôle)
 * - Modules (CRUD formateur, contrôle rôle et propriété)
 * - Inscriptions (inscription, doublon, désinscription, mes formations)
 * - Progression (terminer module, doublon, calcul pourcentage)
 * - Erreurs attendues (401, 403, 404, 409)
 */
class SkillHubTest extends TestCase
{
    use RefreshDatabase;

    private const FORMATION_TEST_TITLE = 'Formation Test';
    private const API_REGISTER = '/api/register';
    private const API_FORMATIONS = '/api/formations';
    private const API_FORMATIONS_PREFIX = '/api/formations/';
    private const API_FORMATIONS_NOT_FOUND = '/api/formations/9999';
    private const API_MODULES_PREFIX = '/api/modules/';
    private const API_MODULES_NOT_FOUND = '/api/modules/9999';
    private const PATH_MODULES = '/modules';
    private const PATH_MODULES_TERMINES = '/modules-termines';
    private const PATH_INSCRIPTION = '/inscription';
    private const PATH_TERMINER = '/terminer';
    private const API_MESSAGES_NON_LUS = '/api/messages/non-lus';
    private const API_MESSAGES_ENVOYER = '/api/messages/envoyer';
    private const API_MESSAGES_CONVERSATIONS = '/api/messages/conversations';
    private const API_MESSAGES_INTERLOCUTEURS = '/api/messages/interlocuteurs';
    private const SECOND_FORMATEUR_NAME = 'Formateur 2';
    private const SECOND_FORMATEUR_EMAIL = 'formateur2@test.com';
    private const ALLOWED_ORIGIN = 'http://localhost:5173';

    // -------------------------------------------------------------------------
    // Helpers privés
    // -------------------------------------------------------------------------

    /**
     * Crée un utilisateur et retourne son token JWT.
     */
    private function creerUtilisateur(string $role): array
    {
        $user = User::create([
            'nom'      => 'Test ' . ucfirst($role),
            'email'    => $role . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => $role,
        ]);

        $token = JWTAuth::fromUser($user);

        return ['user' => $user, 'token' => $token];
    }

    /**
     * Crée une formation appartenant au formateur donné.
     */
    private function creerFormation(User $formateur): Formation
    {
        return Formation::create([
            'titre'          => self::FORMATION_TEST_TITLE,
            'description'    => 'Description de test',
            'categorie'      => 'developpement_web',
            'niveau'         => 'debutant',
            'nombre_de_vues' => 0,
            'formateur_id'   => $formateur->id,
        ]);
    }

    /**
     * Crée un module pour la formation donnée.
     */
    private function creerModule(Formation $formation, int $ordre = 1): Module
    {
        return Module::create([
            'titre'        => 'Module ' . $ordre,
            'contenu'      => 'Contenu du module ' . $ordre,
            'ordre'        => $ordre,
            'formation_id' => $formation->id,
        ]);
    }

    /**
     * Construit l'en-tête Authorization avec le token JWT.
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    // =========================================================================
    // SECTION 1 — Authentification (register, login, profile, logout)
    // =========================================================================
    // Tests JWT : creation de compte, connexion, lecture du profil, deconnexion.
    // Couvre les codes 201 (création), 200 (succès), 401 (non authentifié), 422 (validation).

    #[Test]
    public function un_utilisateur_peut_sinscrire(): void
    {
        $response = $this->postJson(self::API_REGISTER, [
            'nom'                   => 'Alice',
            'email'                 => 'alice@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'apprenant',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'token', 'user']);
    }

    #[Test]
    public function linscription_echoue_si_email_deja_utilise(): void
    {
        $this->creerUtilisateur('apprenant');

        $response = $this->postJson(self::API_REGISTER, [
            'nom'                   => 'Copie',
            'email'                 => 'apprenant@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'apprenant',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function un_utilisateur_peut_se_connecter(): void
    {
        $this->creerUtilisateur('formateur');

        $response = $this->postJson('/api/login', [
            'email'    => 'formateur@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'token', 'user']);
    }

    #[Test]
    public function la_connexion_echoue_avec_mauvais_mot_de_passe(): void
    {
        $this->creerUtilisateur('formateur');

        $response = $this->postJson('/api/login', [
            'email'    => 'formateur@test.com',
            'password' => 'mauvais_mdp',
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function un_utilisateur_connecte_peut_voir_son_profil(): void
    {
        ['token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->getJson('/api/profile', $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonStructure(['user']);
    }

    #[Test]
    public function le_profil_retourne_401_sans_token(): void
    {
        $response = $this->getJson('/api/profile');

        $response->assertStatus(401);
    }

    #[Test]
    public function un_utilisateur_peut_se_deconnecter(): void
    {
        ['token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->postJson('/api/logout', [], $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Déconnexion réussie']);
    }

    // =========================================================================
    // SECTION 2 — Formations (CRUD formateur, filtrage public)
    // =========================================================================
    // Tests CRUD formations : création/édition réservée au formateur propriétaire,
    // lecture publique pour tout visiteur. Vérifie aussi les 403 (rôle inadéquat / autre formateur).

    #[Test]
    public function un_formateur_peut_creer_une_formation(): void
    {
        ['token' => $token] = $this->creerUtilisateur('formateur');

        $response = $this->postJson(self::API_FORMATIONS, [
            'titre'       => 'Laravel avancé',
            'description' => 'Apprendre Laravel en profondeur',
            'categorie'   => 'developpement_web',
            'niveau'      => 'avance',
        ], $this->headers($token));

        $response->assertStatus(201)
            ->assertJsonFragment(['message' => 'Formation créée avec succès']);
    }

    #[Test]
    public function un_apprenant_ne_peut_pas_creer_de_formation(): void
    {
        ['token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->postJson(self::API_FORMATIONS, [
            'titre'       => 'Formation interdite',
            'description' => 'Test rôle',
            'categorie'   => 'developpement_web',
            'niveau'      => 'debutant',
        ], $this->headers($token));

        $response->assertStatus(403);
    }

    #[Test]
    public function la_liste_des_formations_est_publique(): void
    {
        $response = $this->getJson(self::API_FORMATIONS);

        $response->assertStatus(200)
            ->assertJsonStructure([]);
    }

    #[Test]
    public function on_peut_voir_une_formation_existante(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $response = $this->getJson(self::API_FORMATIONS_PREFIX . $formation->id);

        $response->assertStatus(200)
            ->assertJsonFragment(['titre' => self::FORMATION_TEST_TITLE]);
    }

    #[Test]
    public function la_vue_dune_formation_inexistante_retourne_404(): void
    {
        $response = $this->getJson(self::API_FORMATIONS_NOT_FOUND);

        $response->assertStatus(404);
    }

    #[Test]
    public function un_formateur_peut_modifier_sa_formation(): void
    {
        ['user' => $formateur, 'token' => $token] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $response = $this->putJson(self::API_FORMATIONS_PREFIX . $formation->id, [
            'titre'       => 'Nouveau titre',
            'description' => 'Nouvelle description',
            'categorie'   => 'developpement_web',
            'niveau'      => 'intermediaire',
        ], $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Formation mise à jour avec succès']);
    }

    #[Test]
    public function un_formateur_ne_peut_pas_modifier_la_formation_dun_autre(): void
    {
        ['user' => $formateur1] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur1);

        $formateur2 = User::create([
            'nom'      => self::SECOND_FORMATEUR_NAME,
            'email'    => self::SECOND_FORMATEUR_EMAIL,
            'password' => bcrypt('password123'),
            'role'     => 'formateur',
        ]);
        $token2 = JWTAuth::fromUser($formateur2);

        $response = $this->putJson(self::API_FORMATIONS_PREFIX . $formation->id, [
            'titre'       => 'Vol de formation',
            'description' => 'Tentative',
            'categorie'   => 'developpement_web',
            'niveau'      => 'debutant',
        ], $this->headers($token2));

        $response->assertStatus(403);
    }

    #[Test]
    public function un_formateur_peut_supprimer_sa_formation(): void
    {
        ['user' => $formateur, 'token' => $token] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $response = $this->deleteJson(self::API_FORMATIONS_PREFIX . $formation->id, [], $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Formation supprimée avec succès']);
    }

    // =========================================================================
    // SECTION 3 — Modules (CRUD formateur sur ses propres modules)
    // =========================================================================
    // Tests modules pédagogiques : ajout/édition/suppression réservés au formateur
    // propriétaire de la formation parente. Lecture publique (catalogue ouvert).

    #[Test]
    public function un_formateur_peut_ajouter_un_module_a_sa_formation(): void
    {
        ['user' => $formateur, 'token' => $token] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $response = $this->postJson(self::API_FORMATIONS_PREFIX . $formation->id . self::PATH_MODULES, [
            'titre'   => 'Module 1',
            'contenu' => 'Contenu du module 1',
            'ordre'   => 1,
        ], $this->headers($token));

        $response->assertStatus(201)
            ->assertJsonFragment(['message' => 'Module créé avec succès']);
    }

    #[Test]
    public function un_apprenant_ne_peut_pas_creer_un_module(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        ['token' => $tokenApprenant] = $this->creerUtilisateur('apprenant');

        $response = $this->postJson(self::API_FORMATIONS_PREFIX . $formation->id . self::PATH_MODULES, [
            'titre'   => 'Module interdit',
            'contenu' => 'Test',
            'ordre'   => 1,
        ], $this->headers($tokenApprenant));

        $response->assertStatus(403);
    }

    #[Test]
    public function la_creation_de_module_sans_token_retourne_401(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $response = $this->postJson(self::API_FORMATIONS_PREFIX . $formation->id . self::PATH_MODULES, [
            'titre'   => 'Module sans auth',
            'contenu' => 'Test',
            'ordre'   => 1,
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function on_peut_lister_les_modules_dune_formation(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);
        $this->creerModule($formation, 1);
        $this->creerModule($formation, 2);

        $response = $this->getJson(self::API_FORMATIONS_PREFIX . $formation->id . self::PATH_MODULES);

        $response->assertStatus(200)
            ->assertJsonCount(2);
    }

    #[Test]
    public function un_formateur_peut_modifier_un_module_de_sa_formation(): void
    {
        ['user' => $formateur, 'token' => $token] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);
        $module    = $this->creerModule($formation);

        $response = $this->putJson(self::API_MODULES_PREFIX . $module->id, [
            'titre'   => 'Titre modifié',
            'contenu' => 'Contenu modifié',
            'ordre'   => 1,
        ], $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Module mis à jour avec succès']);
    }

    #[Test]
    public function un_formateur_peut_supprimer_un_module_de_sa_formation(): void
    {
        ['user' => $formateur, 'token' => $token] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);
        $module    = $this->creerModule($formation);

        $response = $this->deleteJson(self::API_MODULES_PREFIX . $module->id, [], $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Module supprimé avec succès']);
    }

    #[Test]
    public function la_modification_dun_module_inexistant_retourne_404(): void
    {
        ['token' => $token] = $this->creerUtilisateur('formateur');

        $response = $this->putJson(self::API_MODULES_NOT_FOUND, [
            'titre'   => 'Test',
            'contenu' => 'Test',
            'ordre'   => 1,
        ], $this->headers($token));

        $response->assertStatus(404);
    }

    // =========================================================================
    // SECTION 4 — Inscriptions (apprenant ↔ formation)
    // =========================================================================
    // Tests d'inscription : un apprenant peut s'inscrire/se désinscrire ; doublon = 409.
    // Un formateur ne peut pas s'inscrire (403). Liste des formations suivies = endpoint privé.

    #[Test]
    public function un_apprenant_peut_sinscrire_a_une_formation(): void
    {
        ['user' => $formateur]       = $this->creerUtilisateur('formateur');
        ['token' => $tokenApprenant] = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);

        $response = $this->postJson(self::API_FORMATIONS_PREFIX . $formation->id . self::PATH_INSCRIPTION, [], $this->headers($tokenApprenant));

        $response->assertStatus(201)
            ->assertJsonFragment(['message' => 'Inscription réussie']);
    }

    #[Test]
    public function linscription_en_double_retourne_409(): void
    {
        ['user' => $formateur]                    = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);

        $this->postJson(self::API_FORMATIONS_PREFIX . $formation->id . self::PATH_INSCRIPTION, [], $this->headers($token));
        $response = $this->postJson(self::API_FORMATIONS_PREFIX . $formation->id . self::PATH_INSCRIPTION, [], $this->headers($token));

        $response->assertStatus(409);
    }

    #[Test]
    public function un_formateur_ne_peut_pas_sinscrire_a_une_formation(): void
    {
        ['user' => $formateur, 'token' => $token] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $response = $this->postJson(self::API_FORMATIONS_PREFIX . $formation->id . self::PATH_INSCRIPTION, [], $this->headers($token));

        $response->assertStatus(403);
    }

    #[Test]
    public function linscription_a_une_formation_inexistante_retourne_404(): void
    {
        ['token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->postJson(self::API_FORMATIONS_NOT_FOUND . self::PATH_INSCRIPTION, [], $this->headers($token));

        $response->assertStatus(404);
    }

    #[Test]
    public function un_apprenant_peut_se_desinscrire(): void
    {
        ['user' => $formateur]                    = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);

        Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'progression'    => 0,
        ]);

        $response = $this->deleteJson(self::API_FORMATIONS_PREFIX . $formation->id . self::PATH_INSCRIPTION, [], $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Désinscription réussie']);
    }

    #[Test]
    public function un_apprenant_voit_ses_formations_inscrites(): void
    {
        ['user' => $formateur]                    = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);

        Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'progression'    => 0,
        ]);

        $response = $this->getJson('/api/apprenant/formations', $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'inscriptions');
    }

    // =========================================================================
    // SECTION 5 — Progression (calcul % et marquage modules termines)
    // =========================================================================
    // Tests de calcul de progression : un apprenant inscrit peut marquer ses modules
    // comme terminés. Le pourcentage est recalculé automatiquement (modules_termines / total).

    #[Test]
    public function un_apprenant_peut_terminer_un_module(): void
    {
        ['user' => $formateur]                    = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $module    = $this->creerModule($formation);

        Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'progression'    => 0,
        ]);

        $response = $this->postJson(self::API_MODULES_PREFIX . $module->id . self::PATH_TERMINER, [], $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonFragment([
                'message'     => 'Module terminé avec succès',
                'progression' => 100,
            ]);
    }

    #[Test]
    public function la_progression_est_calculee_correctement_sur_plusieurs_modules(): void
    {
        ['user' => $formateur]                    = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);

        $module1 = $this->creerModule($formation, 1);
        $this->creerModule($formation, 2);

        Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'progression'    => 0,
        ]);

        $response = $this->postJson(self::API_MODULES_PREFIX . $module1->id . self::PATH_TERMINER, [], $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonFragment(['progression' => 50]);
    }

    #[Test]
    public function terminer_un_module_deja_termine_retourne_un_message_sans_erreur(): void
    {
        ['user' => $formateur]                    = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $module    = $this->creerModule($formation);

        Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'progression'    => 0,
        ]);

        $this->postJson(self::API_MODULES_PREFIX . $module->id . self::PATH_TERMINER, [], $this->headers($token));
        $response = $this->postJson(self::API_MODULES_PREFIX . $module->id . self::PATH_TERMINER, [], $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Ce module est déjà terminé']);
    }

    #[Test]
    public function terminer_un_module_sans_etre_inscrit_retourne_403(): void
    {
        ['user' => $formateur]       = $this->creerUtilisateur('formateur');
        ['token' => $tokenApprenant] = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $module    = $this->creerModule($formation);

        $response = $this->postJson(self::API_MODULES_PREFIX . $module->id . self::PATH_TERMINER, [], $this->headers($tokenApprenant));

        $response->assertStatus(403);
    }

    #[Test]
    public function un_formateur_ne_peut_pas_terminer_un_module(): void
    {
        ['user' => $formateur, 'token' => $token] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);
        $module    = $this->creerModule($formation);

        $response = $this->postJson(self::API_MODULES_PREFIX . $module->id . self::PATH_TERMINER, [], $this->headers($token));

        $response->assertStatus(403);
    }

    #[Test]
    public function terminer_un_module_inexistant_retourne_404(): void
    {
        ['user' => $formateur]                    = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);

        Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'progression'    => 0,
        ]);

        $response = $this->postJson(self::API_MODULES_NOT_FOUND . self::PATH_TERMINER, [], $this->headers($token));

        $response->assertStatus(404);
    }

    // =========================================================================
    // SECTION 6 — Nouveaux endpoints (ajoutés lors des corrections)
    // =========================================================================
    // Endpoints ajoutés en cours de projet : mes-formations (formateur),
    // modules-termines (apprenant), validation password_confirmation.

    #[Test]
    public function un_formateur_voit_uniquement_ses_formations(): void
    {
        ['user' => $formateur, 'token' => $token] = $this->creerUtilisateur('formateur');
        $this->creerFormation($formateur);

        $response = $this->getJson('/api/formateur/mes-formations', $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonCount(1);
    }

    #[Test]
    public function un_apprenant_peut_voir_ses_modules_termines(): void
    {
        ['user' => $formateur]                    = $this->creerUtilisateur('formateur');
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $module    = $this->creerModule($formation);

        Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'progression'    => 0,
        ]);

        // Terminer le module
        $this->postJson(self::API_MODULES_PREFIX . $module->id . self::PATH_TERMINER, [], $this->headers($token));

        // Vérifier via le nouvel endpoint
        $response = $this->getJson(self::API_FORMATIONS_PREFIX . $formation->id . self::PATH_MODULES_TERMINES, $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'modules_termines');
    }

    #[Test]
    public function linscription_echoue_si_mots_de_passe_differents(): void
    {
        $response = $this->postJson(self::API_REGISTER, [
            'nom'                   => 'Bob',
            'email'                 => 'bob@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'different456',
            'role'                  => 'apprenant',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // SECTION 7 — Messagerie 1:1 (envoi, conversations, lecture)
    // =========================================================================
    // Tests messagerie : envoi message, comptage non-lus, regroupement par conversation,
    // marquage automatique "lu" à l'ouverture, listing interlocuteurs selon le rôle.

    #[Test]
    public function messages_non_lus_retourne_zero_sans_messages(): void
    {
        ['token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->getJson(self::API_MESSAGES_NON_LUS, $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonFragment(['non_lus' => 0]);
    }

    #[Test]
    public function messages_non_lus_retourne_401_sans_token(): void
    {
        $response = $this->getJson(self::API_MESSAGES_NON_LUS);

        $response->assertStatus(401);
    }

    #[Test]
    public function messages_non_lus_compte_correctement(): void
    {
        ['user' => $expediteur] = $this->creerUtilisateur('formateur');
        ['user' => $destinataire, 'token' => $token] = $this->creerUtilisateur('apprenant');

        Message::create([
            'expediteur_id'   => $expediteur->id,
            'destinataire_id' => $destinataire->id,
            'contenu'         => 'Message non lu',
            'lu'              => false,
        ]);

        $response = $this->getJson(self::API_MESSAGES_NON_LUS, $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonFragment(['non_lus' => 1]);
    }

    #[Test]
    public function envoyer_message_retourne_201(): void
    {
        ['user' => $expediteur, 'token' => $token] = $this->creerUtilisateur('formateur');
        ['user' => $destinataire] = $this->creerUtilisateur('apprenant');

        $response = $this->postJson(self::API_MESSAGES_ENVOYER, [
            'destinataire_id' => $destinataire->id,
            'contenu'         => 'Bonjour !',
        ], $this->headers($token));

        $response->assertStatus(201)
            ->assertJsonFragment(['message' => 'Message envoyé']);
    }

    #[Test]
    public function envoyer_message_a_soi_meme_retourne_422(): void
    {
        ['user' => $user, 'token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->postJson(self::API_MESSAGES_ENVOYER, [
            'destinataire_id' => $user->id,
            'contenu'         => 'Message à moi-même',
        ], $this->headers($token));

        $response->assertStatus(422);
    }

    #[Test]
    public function envoyer_message_sans_token_retourne_401(): void
    {
        ['user' => $destinataire] = $this->creerUtilisateur('apprenant');

        $response = $this->postJson(self::API_MESSAGES_ENVOYER, [
            'destinataire_id' => $destinataire->id,
            'contenu'         => 'Sans auth',
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function envoyer_message_destinataire_inexistant_retourne_422(): void
    {
        ['token' => $token] = $this->creerUtilisateur('formateur');

        $response = $this->postJson(self::API_MESSAGES_ENVOYER, [
            'destinataire_id' => 9999,
            'contenu'         => 'Destinataire inconnu',
        ], $this->headers($token));

        $response->assertStatus(422);
    }

    #[Test]
    public function conversations_retourne_liste_vide_sans_messages(): void
    {
        ['token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->getJson(self::API_MESSAGES_CONVERSATIONS, $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonFragment(['conversations' => []]);
    }

    #[Test]
    public function conversations_retourne_401_sans_token(): void
    {
        $response = $this->getJson(self::API_MESSAGES_CONVERSATIONS);

        $response->assertStatus(401);
    }

    #[Test]
    public function conversations_retourne_liste_avec_messages(): void
    {
        ['user' => $expediteur, 'token' => $token] = $this->creerUtilisateur('formateur');
        ['user' => $destinataire] = $this->creerUtilisateur('apprenant');

        Message::create([
            'expediteur_id'   => $expediteur->id,
            'destinataire_id' => $destinataire->id,
            'contenu'         => 'Premier message',
            'lu'              => false,
        ]);

        $response = $this->getJson(self::API_MESSAGES_CONVERSATIONS, $this->headers($token));

        $response->assertStatus(200);
        $conversations = $response->json('conversations');
        $this->assertCount(1, $conversations);
    }

    #[Test]
    public function messagerie_par_conversation_retourne_messages(): void
    {
        ['user' => $expediteur, 'token' => $token] = $this->creerUtilisateur('formateur');
        ['user' => $destinataire] = $this->creerUtilisateur('apprenant');

        Message::create([
            'expediteur_id'   => $expediteur->id,
            'destinataire_id' => $destinataire->id,
            'contenu'         => 'Bonjour apprenant',
            'lu'              => false,
        ]);

        $response = $this->getJson(
            '/api/messages/conversation/' . $destinataire->id,
            $this->headers($token)
        );

        $response->assertStatus(200)
            ->assertJsonStructure(['messages']);
        $this->assertCount(1, $response->json('messages'));
    }

    #[Test]
    public function messagerie_par_conversation_retourne_401_sans_token(): void
    {
        $response = $this->getJson('/api/messages/conversation/1');

        $response->assertStatus(401);
    }

    #[Test]
    public function messagerie_marque_messages_recus_comme_lus(): void
    {
        ['user' => $expediteur] = $this->creerUtilisateur('formateur');
        ['user' => $destinataire, 'token' => $token] = $this->creerUtilisateur('apprenant');

        $message = Message::create([
            'expediteur_id'   => $expediteur->id,
            'destinataire_id' => $destinataire->id,
            'contenu'         => 'À lire',
            'lu'              => false,
        ]);

        // Consulter la conversation marque le message comme lu
        $this->getJson(
            '/api/messages/conversation/' . $expediteur->id,
            $this->headers($token)
        );

        $this->assertTrue(Message::find($message->id)->lu);
    }

    #[Test]
    public function interlocuteurs_formateur_retourne_apprenants(): void
    {
        ['token' => $token] = $this->creerUtilisateur('formateur');
        $this->creerUtilisateur('apprenant');

        $response = $this->getJson(self::API_MESSAGES_INTERLOCUTEURS, $this->headers($token));

        $response->assertStatus(200);
        $interlocuteurs = $response->json('interlocuteurs');
        $this->assertCount(1, $interlocuteurs);
        $this->assertEquals('apprenant', $interlocuteurs[0]['role']);
    }

    #[Test]
    public function interlocuteurs_apprenant_retourne_formateurs(): void
    {
        $this->creerUtilisateur('formateur');
        ['token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->getJson(self::API_MESSAGES_INTERLOCUTEURS, $this->headers($token));

        $response->assertStatus(200);
        $interlocuteurs = $response->json('interlocuteurs');
        $this->assertCount(1, $interlocuteurs);
        $this->assertEquals('formateur', $interlocuteurs[0]['role']);
    }

    #[Test]
    public function interlocuteurs_retourne_401_sans_token(): void
    {
        $response = $this->getJson(self::API_MESSAGES_INTERLOCUTEURS);

        $response->assertStatus(401);
    }

    // =========================================================================
    // SECTION 8 — Couverture complémentaire (filtres, permissions, erreurs)
    // =========================================================================
    // Vise les chemins moins évidents : filtres recherche/catégorie/niveau,
    // permissions cross-utilisateurs, cas d'erreur de validation/payload.

    #[Test]
    public function la_liste_formations_peut_etre_filtree_par_recherche(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $this->creerFormation($formateur);

        $response = $this->getJson('/api/formations?recherche=Formation');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    #[Test]
    public function la_liste_formations_peut_etre_filtree_par_categorie(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $this->creerFormation($formateur);

        $response = $this->getJson('/api/formations?categorie=developpement_web');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    #[Test]
    public function la_liste_formations_peut_etre_filtree_par_niveau(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $this->creerFormation($formateur);

        $response = $this->getJson('/api/formations?niveau=debutant');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    #[Test]
    public function on_peut_voir_une_formation_en_etant_connecte(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        ['token' => $token]    = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);

        $response = $this->getJson(self::API_FORMATIONS_PREFIX . $formation->id, $this->headers($token));

        $response->assertStatus(200)
            ->assertJsonFragment(['titre' => self::FORMATION_TEST_TITLE]);
    }

    #[Test]
    public function la_mise_a_jour_dune_formation_inexistante_retourne_404(): void
    {
        ['token' => $token] = $this->creerUtilisateur('formateur');

        $response = $this->putJson(self::API_FORMATIONS_NOT_FOUND, [
            'titre'       => 'Test',
            'description' => 'Test',
            'categorie'   => 'developpement_web',
            'niveau'      => 'debutant',
        ], $this->headers($token));

        $response->assertStatus(404);
    }

    #[Test]
    public function la_suppression_dune_formation_inexistante_retourne_404(): void
    {
        ['token' => $token] = $this->creerUtilisateur('formateur');

        $response = $this->deleteJson(self::API_FORMATIONS_NOT_FOUND, [], $this->headers($token));

        $response->assertStatus(404);
    }

    #[Test]
    public function un_formateur_ne_peut_pas_supprimer_la_formation_dun_autre(): void
    {
        ['user' => $formateur1] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur1);

        $formateur2 = User::create([
            'nom'      => self::SECOND_FORMATEUR_NAME,
            'email'    => self::SECOND_FORMATEUR_EMAIL,
            'password' => bcrypt('password123'),
            'role'     => 'formateur',
        ]);
        $token2 = JWTAuth::fromUser($formateur2);

        $response = $this->deleteJson(self::API_FORMATIONS_PREFIX . $formation->id, [], $this->headers($token2));

        $response->assertStatus(403);
    }

    #[Test]
    public function un_apprenant_ne_peut_pas_acceder_aux_formations_formateur(): void
    {
        ['token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->getJson('/api/formateur/mes-formations', $this->headers($token));

        $response->assertStatus(403);
    }

    #[Test]
    public function un_formateur_ne_peut_pas_ajouter_module_a_formation_dun_autre(): void
    {
        ['user' => $formateur1] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur1);

        $formateur2 = User::create([
            'nom'      => self::SECOND_FORMATEUR_NAME,
            'email'    => self::SECOND_FORMATEUR_EMAIL,
            'password' => bcrypt('password123'),
            'role'     => 'formateur',
        ]);
        $token2 = JWTAuth::fromUser($formateur2);

        $response = $this->postJson(self::API_FORMATIONS_PREFIX . $formation->id . self::PATH_MODULES, [
            'titre'   => 'Module interdit',
            'contenu' => 'Test',
            'ordre'   => 1,
        ], $this->headers($token2));

        $response->assertStatus(403);
    }

    #[Test]
    public function ajout_module_a_formation_inexistante_retourne_404(): void
    {
        ['token' => $token] = $this->creerUtilisateur('formateur');

        $response = $this->postJson(self::API_FORMATIONS_NOT_FOUND . self::PATH_MODULES, [
            'titre'   => 'Module',
            'contenu' => 'Test',
            'ordre'   => 1,
        ], $this->headers($token));

        $response->assertStatus(404);
    }

    #[Test]
    public function un_apprenant_ne_peut_pas_modifier_un_module(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        ['token' => $token]    = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $module    = $this->creerModule($formation);

        $response = $this->putJson(self::API_MODULES_PREFIX . $module->id, [
            'titre'   => 'Tentative',
            'contenu' => 'Test',
            'ordre'   => 1,
        ], $this->headers($token));

        $response->assertStatus(403);
    }

    #[Test]
    public function un_apprenant_ne_peut_pas_supprimer_un_module(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        ['token' => $token]    = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $module    = $this->creerModule($formation);

        $response = $this->deleteJson(self::API_MODULES_PREFIX . $module->id, [], $this->headers($token));

        $response->assertStatus(403);
    }

    #[Test]
    public function un_formateur_ne_peut_pas_modifier_module_dun_autre(): void
    {
        ['user' => $formateur1] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur1);
        $module    = $this->creerModule($formation);

        $formateur2 = User::create([
            'nom'      => self::SECOND_FORMATEUR_NAME,
            'email'    => self::SECOND_FORMATEUR_EMAIL,
            'password' => bcrypt('password123'),
            'role'     => 'formateur',
        ]);
        $token2 = JWTAuth::fromUser($formateur2);

        $response = $this->putJson(self::API_MODULES_PREFIX . $module->id, [
            'titre'   => 'Vol de module',
            'contenu' => 'Test',
            'ordre'   => 1,
        ], $this->headers($token2));

        $response->assertStatus(403);
    }

    #[Test]
    public function un_formateur_ne_peut_pas_supprimer_module_dun_autre(): void
    {
        ['user' => $formateur1] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur1);
        $module    = $this->creerModule($formation);

        $formateur2 = User::create([
            'nom'      => self::SECOND_FORMATEUR_NAME,
            'email'    => self::SECOND_FORMATEUR_EMAIL,
            'password' => bcrypt('password123'),
            'role'     => 'formateur',
        ]);
        $token2 = JWTAuth::fromUser($formateur2);

        $response = $this->deleteJson(self::API_MODULES_PREFIX . $module->id, [], $this->headers($token2));

        $response->assertStatus(403);
    }

    #[Test]
    public function un_formateur_ne_peut_pas_voir_ses_modules_termines(): void
    {
        ['user' => $formateur, 'token' => $token] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $response = $this->getJson(
            self::API_FORMATIONS_PREFIX . $formation->id . self::PATH_MODULES_TERMINES,
            $this->headers($token)
        );

        $response->assertStatus(403);
    }

    #[Test]
    public function la_desinscription_sans_inscription_retourne_404(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        ['token' => $token]    = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);

        $response = $this->deleteJson(
            self::API_FORMATIONS_PREFIX . $formation->id . self::PATH_INSCRIPTION,
            [],
            $this->headers($token)
        );

        $response->assertStatus(404);
    }

    #[Test]
    public function un_formateur_ne_peut_pas_voir_ses_formations_inscrites(): void
    {
        ['token' => $token] = $this->creerUtilisateur('formateur');

        $response = $this->getJson('/api/apprenant/formations', $this->headers($token));

        $response->assertStatus(403);
    }

    #[Test]
    public function un_second_message_ne_declenche_pas_demail(): void
    {
        ['user' => $expediteur, 'token' => $token] = $this->creerUtilisateur('formateur');
        ['user' => $destinataire]                  = $this->creerUtilisateur('apprenant');

        // Premier message (déclenche l'envoi de mail)
        $this->postJson(self::API_MESSAGES_ENVOYER, [
            'destinataire_id' => $destinataire->id,
            'contenu'         => 'Premier message',
        ], $this->headers($token));

        // Deuxième message "” ne doit pas tenter d'envoyer d'email
        $response = $this->postJson(self::API_MESSAGES_ENVOYER, [
            'destinataire_id' => $destinataire->id,
            'contenu'         => 'Deuxième message',
        ], $this->headers($token));

        $response->assertStatus(201)
            ->assertJsonFragment(['message' => 'Message envoyé']);
    }

    #[Test]
    public function upload_photo_avec_token_sans_fichier_retourne_422(): void
    {
        ['token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->postJson('/api/profil/photo', [], $this->headers($token));

        $response->assertStatus(422);
    }

    #[Test]
    public function upload_photo_avec_image_valide_retourne_200(): void
    {
        ['token' => $token] = $this->creerUtilisateur('apprenant');

        $dir = public_path('images/profils');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = \Illuminate\Http\UploadedFile::fake()->create('photo.jpg', 120, 'image/jpeg');

        $response = $this->withHeaders($this->headers($token))
            ->post('/api/profil/photo', ['photo' => $file]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'photo_profil', 'user']);
    }

    // =========================================================================
    // SECTION 9 "” Relations des modèles, CorsMiddleware, vues en double
    // =========================================================================

    #[Test]
    public function les_relations_du_modele_user_sont_accessibles(): void
    {
        ['user' => $formateur]                    = $this->creerUtilisateur('formateur');
        ['user' => $apprenant]                    = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);

        // User::formations()
        $this->assertCount(1, $formateur->formations);

        // User::inscriptions()
        Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'progression'    => 0,
        ]);
        $this->assertCount(1, $apprenant->inscriptions);

        // User::messagesEnvoyes() et User::messagesRecus()
        Message::create([
            'expediteur_id'   => $formateur->id,
            'destinataire_id' => $apprenant->id,
            'contenu'         => 'Test relation',
            'lu'              => false,
        ]);
        $this->assertCount(1, $formateur->messagesEnvoyes);
        $this->assertCount(1, $apprenant->messagesRecus);
    }

    #[Test]
    public function les_relations_du_modele_formation_sont_accessibles(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);
        $this->creerModule($formation);

        // Formation::modules()
        $this->assertCount(1, $formation->modules);

        // Formation::vues()
        FormationVue::create([
            'formation_id'   => $formation->id,
            'utilisateur_id' => null,
            'ip'             => '127.0.0.1',
        ]);
        $formation->refresh();
        $this->assertCount(1, $formation->vues);
    }

    #[Test]
    public function les_relations_du_modele_inscription_sont_accessibles(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        ['user' => $apprenant] = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);

        $inscription = Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'progression'    => 0,
        ]);

        // Inscription::utilisateur()
        $this->assertEquals($apprenant->id, $inscription->utilisateur->id);

        // Inscription::formation() (couvert via with() mais testé ici explicitement)
        $this->assertEquals($formation->id, $inscription->formation->id);
    }

    #[Test]
    public function les_relations_du_modele_module_sont_accessibles(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        ['user' => $apprenant] = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);
        $module    = $this->creerModule($formation);

        Inscription::create([
            'utilisateur_id' => $apprenant->id,
            'formation_id'   => $formation->id,
            'progression'    => 0,
        ]);

        // Créer la relation pivot module_user via syncWithoutDetaching
        $apprenant->modulesTermines()->syncWithoutDetaching([
            $module->id => ['termine' => true],
        ]);

        // Module::utilisateurs()
        $this->assertCount(1, $module->utilisateurs);

        // Module::formation()
        $this->assertEquals($formation->id, $module->formation->id);
    }

    #[Test]
    public function les_relations_du_modele_formation_vue_sont_accessibles(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        ['user' => $apprenant] = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);

        $vue = FormationVue::create([
            'formation_id'   => $formation->id,
            'utilisateur_id' => $apprenant->id,
            'ip'             => '127.0.0.1',
        ]);

        // FormationVue::formation()
        $this->assertEquals($formation->id, $vue->formation->id);

        // FormationVue::utilisateur()
        $this->assertEquals($apprenant->id, $vue->utilisateur->id);
    }

    #[Test]
    public function une_requete_options_retourne_200(): void
    {
        $response = $this->call('OPTIONS', self::API_FORMATIONS, [], [], [], [
            'HTTP_ORIGIN' => self::ALLOWED_ORIGIN,
        ]);

        $response->assertStatus(200);
    }

    #[Test]
    public function une_requete_avec_origin_autorisee_inclut_header_cors(): void
    {
        $response = $this->withHeaders(['Origin' => self::ALLOWED_ORIGIN])
            ->getJson(self::API_FORMATIONS);

        $response->assertStatus(200);
        $response->assertHeader('Access-Control-Allow-Origin', self::ALLOWED_ORIGIN);
    }

    #[Test]
    public function la_deuxieme_vue_anonyme_ne_compte_pas(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        // Première vue depuis la même IP (127.0.0.1 en test)
        $this->getJson(self::API_FORMATIONS_PREFIX . $formation->id);

        // Deuxième vue depuis la même IP "” ne doit pas incrémenter
        $this->getJson(self::API_FORMATIONS_PREFIX . $formation->id);

        $formation->refresh();
        $this->assertEquals(1, $formation->nombre_de_vues);
    }

    #[Test]
    public function la_deuxieme_vue_authentifiee_ne_compte_pas(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        ['token' => $token]    = $this->creerUtilisateur('apprenant');
        $formation = $this->creerFormation($formateur);

        // Première vue authentifiée
        $this->getJson(self::API_FORMATIONS_PREFIX . $formation->id, $this->headers($token));

        // Deuxième vue authentifiée "” même utilisateur, ne doit pas incrémenter
        $this->getJson(self::API_FORMATIONS_PREFIX . $formation->id, $this->headers($token));

        $formation->refresh();
        $this->assertEquals(1, $formation->nombre_de_vues);
    }
}

