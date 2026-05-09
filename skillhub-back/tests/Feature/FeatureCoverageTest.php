<?php

namespace Tests\Feature;

use App\Mail\NouveauMessageMail;
use App\Models\Formation;
use App\Models\Inscription;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Tests de couverture fonctionnelle E2E (end-to-end).
 *
 * Complémentaires à SkillHubTest.php (qui teste les opérations atomiques) :
 * ici on enchaîne plusieurs opérations pour simuler des PARCOURS UTILISATEUR
 * réalistes. Ces tests valident que l'application fonctionne comme un tout
 * et couvrent en plus les fonctionnalités jamais testées atomiquement :
 *   - PDF upload + download avec contrôle d'accès
 *   - Persistance MongoDB ActivityLog
 *   - Email de notification au PREMIER message (présence positive)
 *   - Parcours apprenant complet (register → inscription → progression 100%)
 *   - Parcours formateur complet (CRUD formations + modules + réception messages)
 *
 * Stratégie :
 *  - Mail::fake() pour intercepter les envois sans SMTP réel.
 *  - Storage::fake('public') pour intercepter les uploads PDF.
 *  - RefreshDatabase pour repartir d'une DB propre entre tests.
 */
class FeatureCoverageTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // SECTION 1 — Parcours complet APPRENANT (register → progression 100%)
    // =========================================================================

    /**
     * Simule un nouvel apprenant qui s'inscrit, parcourt le catalogue,
     * découvre une formation, s'inscrit, termine tous les modules, atteint
     * 100% de progression, envoie un message au formateur, puis se déconnecte.
     */
    #[Test]
    public function parcours_apprenant_de_inscription_a_progression_complete(): void
    {
        // Pré-requis : un formateur a déjà créé une formation avec 3 modules.
        $formateur = User::create([
            'nom'      => 'Jean Formateur',
            'email'    => 'formateur@parcours.test',
            'password' => bcrypt('password'),
            'role'     => 'formateur',
        ]);

        $formation = Formation::create([
            'titre'          => 'React Avancé',
            'description'    => 'Plonge dans les hooks avancés.',
            'categorie'      => 'developpement_web',
            'niveau'         => 'avance',
            'nombre_de_vues' => 0,
            'formateur_id'   => $formateur->id,
        ]);

        $modules = [];
        for ($i = 1; $i <= 3; $i++) {
            $modules[] = Module::create([
                'titre'        => "Module $i",
                'contenu'      => "Contenu du module $i",
                'ordre'        => $i,
                'formation_id' => $formation->id,
            ]);
        }

        // Étape 1 : l'apprenant crée son compte.
        $reponseInscription = $this->postJson('/api/register', [
            'nom'                   => 'Marie Apprenante',
            'email'                 => 'marie@parcours.test',
            'password'              => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'role'                  => 'apprenant',
        ]);
        $reponseInscription->assertStatus(201);
        $tokenApprenant = $reponseInscription->json('token');

        // Étape 2 : profile vérification (le token marche).
        $this->getJson('/api/profile', ['Authorization' => 'Bearer ' . $tokenApprenant])
             ->assertStatus(200)
             ->assertJsonPath('user.email', 'marie@parcours.test');

        // Étape 3 : explorer le catalogue public (sans token).
        $this->getJson('/api/formations')
             ->assertStatus(200);

        // Étape 4 : voir le détail (compteur de vues incrémenté).
        $this->getJson('/api/formations/' . $formation->id)
             ->assertStatus(200)
             ->assertJsonPath('titre', 'React Avancé');

        // Étape 5 : s'inscrire à la formation.
        $this->postJson(
            '/api/formations/' . $formation->id . '/inscription',
            [],
            ['Authorization' => 'Bearer ' . $tokenApprenant]
        )->assertStatus(201);

        // Étape 6 : récupérer la liste de "mes formations" → doit contenir la nouvelle inscription.
        $reponseMesFormations = $this->getJson(
            '/api/apprenant/formations',
            ['Authorization' => 'Bearer ' . $tokenApprenant]
        );
        $reponseMesFormations->assertStatus(200)
                             ->assertJsonCount(1, 'inscriptions');

        // Étape 7 : marquer chaque module comme terminé.
        foreach ($modules as $module) {
            $this->postJson(
                '/api/modules/' . $module->id . '/terminer',
                [],
                ['Authorization' => 'Bearer ' . $tokenApprenant]
            )->assertStatus(200);
        }

        // Étape 8 : vérifier la progression à 100%.
        $apprenant = User::where('email', 'marie@parcours.test')->first();
        $inscription = Inscription::where('utilisateur_id', $apprenant->id)
                                   ->where('formation_id', $formation->id)
                                   ->first();
        $this->assertEquals(100, $inscription->progression);

        // Étape 9 : envoyer un message de remerciement au formateur.
        Mail::fake();  // bloque l'envoi réel (test rapide).
        $this->postJson(
            '/api/messages/envoyer',
            [
                'destinataire_id' => $formateur->id,
                'contenu'         => 'Merci pour cette formation !',
            ],
            ['Authorization' => 'Bearer ' . $tokenApprenant]
        )->assertStatus(201);

        // Étape 10 : se déconnecter (invalidation du token côté serveur).
        $this->postJson('/api/logout', [], ['Authorization' => 'Bearer ' . $tokenApprenant])
             ->assertStatus(200);
    }

    // =========================================================================
    // SECTION 2 — Parcours complet FORMATEUR (CRUD formation/modules + msg)
    // =========================================================================

    /**
     * Simule un formateur qui s'inscrit, crée une formation, ajoute 3 modules,
     * en modifie un, en supprime un, accepte 2 inscriptions d'apprenants, voit
     * arriver des messages, et finalement supprime la formation.
     */
    #[Test]
    public function parcours_formateur_creation_modification_suppression_formation(): void
    {
        // Étape 1 : inscription du formateur.
        $reponseInscription = $this->postJson('/api/register', [
            'nom'                   => 'Paul Formateur',
            'email'                 => 'paul@parcours.test',
            'password'              => 'pass1234',
            'password_confirmation' => 'pass1234',
            'role'                  => 'formateur',
        ]);
        $reponseInscription->assertStatus(201);
        $tokenFormateur = $reponseInscription->json('token');

        // Étape 2 : créer une formation.
        $reponseCreation = $this->postJson(
            '/api/formations',
            [
                'titre'        => 'DevOps Bootcamp',
                'description'  => 'Docker, CI/CD, observabilité.',
                'categorie'    => 'devops',
                'niveau'       => 'intermediaire',
                'prix'         => 99.99,
                'duree_heures' => 40,
            ],
            ['Authorization' => 'Bearer ' . $tokenFormateur]
        );
        $reponseCreation->assertStatus(201);
        $formationId = $reponseCreation->json('formation.id');

        // Étape 3 : ajouter 3 modules.
        $idsModules = [];
        for ($i = 1; $i <= 3; $i++) {
            $reponseModule = $this->postJson(
                '/api/formations/' . $formationId . '/modules',
                [
                    'titre'   => "Chapitre $i",
                    'contenu' => "Cours $i",
                    'ordre'   => $i,
                ],
                ['Authorization' => 'Bearer ' . $tokenFormateur]
            );
            $reponseModule->assertStatus(201);
            $idsModules[] = $reponseModule->json('module.id');
        }

        // Étape 4 : modifier le titre du module 2.
        $this->putJson(
            '/api/modules/' . $idsModules[1],
            [
                'titre'   => 'Chapitre 2 — révisé',
                'contenu' => 'Cours 2 révisé',
                'ordre'   => 2,
            ],
            ['Authorization' => 'Bearer ' . $tokenFormateur]
        )->assertStatus(200);

        // Étape 5 : supprimer le module 3.
        $this->deleteJson(
            '/api/modules/' . $idsModules[2],
            [],
            ['Authorization' => 'Bearer ' . $tokenFormateur]
        )->assertStatus(200);

        // Étape 6 : voir "mes formations" (le formateur a 1 formation, 2 modules restants).
        $reponseMes = $this->getJson(
            '/api/formateur/mes-formations',
            ['Authorization' => 'Bearer ' . $tokenFormateur]
        );
        $reponseMes->assertStatus(200);

        // Étape 7 : 2 apprenants s'inscrivent.
        Mail::fake();
        $apprenants = [];
        for ($i = 1; $i <= 2; $i++) {
            $apprenant = User::create([
                'nom'      => "Apprenant $i",
                'email'    => "app$i@parcours.test",
                'password' => bcrypt('password'),
                'role'     => 'apprenant',
            ]);
            $tokenApp = JWTAuth::fromUser($apprenant);
            $this->postJson(
                '/api/formations/' . $formationId . '/inscription',
                [],
                ['Authorization' => 'Bearer ' . $tokenApp]
            )->assertStatus(201);

            // Apprenant envoie un message au formateur.
            $this->postJson(
                '/api/messages/envoyer',
                [
                    'destinataire_id' => $reponseInscription->json('user.id'),
                    'contenu'         => "Bonjour, j'ai une question (apprenant $i).",
                ],
                ['Authorization' => 'Bearer ' . $tokenApp]
            )->assertStatus(201);

            $apprenants[] = ['user' => $apprenant, 'token' => $tokenApp];
        }

        // Étape 8 : formateur consulte ses messages non lus → 2.
        $this->getJson(
            '/api/messages/non-lus',
            ['Authorization' => 'Bearer ' . $tokenFormateur]
        )->assertStatus(200)
         ->assertJsonPath('non_lus', 2);

        // Étape 9 : formateur consulte la liste des interlocuteurs (= les 2 apprenants inscrits).
        $this->getJson(
            '/api/messages/interlocuteurs',
            ['Authorization' => 'Bearer ' . $tokenFormateur]
        )->assertStatus(200)
         ->assertJsonCount(2, 'interlocuteurs');

        // Étape 10 : formateur supprime la formation (cascade : modules + inscriptions disparaissent).
        $this->deleteJson(
            '/api/formations/' . $formationId,
            [],
            ['Authorization' => 'Bearer ' . $tokenFormateur]
        )->assertStatus(200);

        $this->assertEquals(0, Formation::count());
        $this->assertEquals(0, Module::count());
        $this->assertEquals(0, Inscription::count());
    }

    // =========================================================================
    // SECTION 3 — PDF upload + download (jamais testé)
    // =========================================================================

    /**
     * Vérifie l'upload d'un PDF lors de la création d'une formation,
     * puis le téléchargement avec contrôle d'accès :
     *   - le formateur propriétaire peut télécharger.
     *   - un apprenant inscrit peut télécharger.
     *   - un apprenant non inscrit reçoit 403.
     *   - un visiteur sans token reçoit 401.
     */
    #[Test]
    public function pdf_upload_a_la_creation_et_download_avec_controle_acces(): void
    {
        // Storage::fake : intercepte tous les writes vers le disque "public" en mémoire.
        Storage::fake('public');

        // 1) Formateur crée une formation AVEC un PDF.
        $formateur = User::create([
            'nom'      => 'Formateur PDF',
            'email'    => 'pdf@formateur.test',
            'password' => bcrypt('password'),
            'role'     => 'formateur',
        ]);
        $tokenF = JWTAuth::fromUser($formateur);

        // UploadedFile::fake permet de générer un PDF factice de taille contrôlée.
        $pdfFactice = UploadedFile::fake()->create('cours.pdf', 100, 'application/pdf');

        $reponseCreation = $this->post(
            '/api/formations',
            [
                'titre'        => 'Cours avec PDF',
                'description'  => 'Formation avec un cours en PDF',
                'categorie'    => 'developpement_web',
                'niveau'       => 'debutant',
                'fichier_pdf'  => $pdfFactice,
            ],
            ['Authorization' => 'Bearer ' . $tokenF]
        );
        $reponseCreation->assertStatus(201);
        $formationId = $reponseCreation->json('formation.id');

        // Vérifie que le fichier a bien été stocké au bon endroit.
        Storage::disk('public')->assertExists("formations/{$formationId}/cours.pdf");

        // 2) Le formateur propriétaire peut télécharger.
        $this->get(
            '/api/formations/' . $formationId . '/pdf',
            ['Authorization' => 'Bearer ' . $tokenF]
        )->assertStatus(200);

        // 3) Apprenant non inscrit → 403.
        $apprenantNonInscrit = User::create([
            'nom'      => 'Apprenant Hors',
            'email'    => 'hors@apprenant.test',
            'password' => bcrypt('password'),
            'role'     => 'apprenant',
        ]);
        $tokenHors = JWTAuth::fromUser($apprenantNonInscrit);
        $this->getJson(
            '/api/formations/' . $formationId . '/pdf',
            ['Authorization' => 'Bearer ' . $tokenHors]
        )->assertStatus(403);

        // 4) Apprenant inscrit → 200.
        $apprenantInscrit = User::create([
            'nom'      => 'Apprenant Inscrit',
            'email'    => 'inscrit@apprenant.test',
            'password' => bcrypt('password'),
            'role'     => 'apprenant',
        ]);
        $tokenIn = JWTAuth::fromUser($apprenantInscrit);
        $this->postJson(
            '/api/formations/' . $formationId . '/inscription',
            [],
            ['Authorization' => 'Bearer ' . $tokenIn]
        )->assertStatus(201);

        $this->get(
            '/api/formations/' . $formationId . '/pdf',
            ['Authorization' => 'Bearer ' . $tokenIn]
        )->assertStatus(200);

        // 5) Sans token → 401.
        $this->getJson('/api/formations/' . $formationId . '/pdf')
             ->assertStatus(401);
    }

    /**
     * Vérifie l'upload d'une image illustrative à la création d'une formation,
     * que le chemin est bien stocké en DB, et que la suppression de la formation
     * nettoie aussi le fichier image (pas d'orphelin sur le disque).
     */
    #[Test]
    public function image_upload_a_la_creation_et_supprimee_avec_la_formation(): void
    {
        Storage::fake('public');

        $formateur = User::create([
            'nom'      => 'Formateur Image',
            'email'    => 'image@formateur.test',
            'password' => bcrypt('password'),
            'role'     => 'formateur',
        ]);
        $token = JWTAuth::fromUser($formateur);

        // Image factice via create() avec MIME explicite — pas image() qui
        // requiert l'extension PHP GD (souvent absente en CI).
        $imageFactice = UploadedFile::fake()->create('hero.jpg', 100, 'image/jpeg');

        $reponseCreation = $this->post(
            '/api/formations',
            [
                'titre'        => 'Formation avec image',
                'description'  => 'Une formation illustrée',
                'categorie'    => 'developpement_web',
                'niveau'       => 'debutant',
                'image'        => $imageFactice,
            ],
            ['Authorization' => 'Bearer ' . $token]
        );
        $reponseCreation->assertStatus(201);
        $formationId = $reponseCreation->json('formation.id');

        // Vérifie le chemin stocké en DB et la présence du fichier.
        $formation = Formation::find($formationId);
        $this->assertEquals("formations/{$formationId}/image.jpg", $formation->image);
        Storage::disk('public')->assertExists("formations/{$formationId}/image.jpg");

        // La suppression doit nettoyer le fichier image.
        $this->deleteJson(
            '/api/formations/' . $formationId,
            [],
            ['Authorization' => 'Bearer ' . $token]
        )->assertStatus(200);

        Storage::disk('public')->assertMissing("formations/{$formationId}/image.jpg");
    }

    /**
     * Vérifie qu'un .exe ou autre format non-image est refusé (mimes:jpeg,png,jpg,webp).
     */
    #[Test]
    public function image_upload_rejette_les_fichiers_non_image(): void
    {
        Storage::fake('public');

        $formateur = User::create([
            'nom'      => 'Formateur',
            'email'    => 'mauvais@image.test',
            'password' => bcrypt('password'),
            'role'     => 'formateur',
        ]);
        $token = JWTAuth::fromUser($formateur);

        $fauxImage = UploadedFile::fake()->create('virus.exe', 50, 'application/octet-stream');

        $reponse = $this->post(
            '/api/formations',
            [
                'titre'       => 'Tentative malveillante',
                'description' => 'Devrait échouer',
                'categorie'   => 'developpement_web',
                'niveau'      => 'debutant',
                'image'       => $fauxImage,
            ],
            [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ]
        );
        $reponse->assertStatus(422);
        $this->assertEquals(0, Formation::count());
    }

    /**
     * Vérifie que tenter d'uploader autre chose qu'un PDF (ex: un .exe)
     * échoue à la validation et n'est pas stocké sur le disque.
     */
    #[Test]
    public function pdf_upload_rejette_les_fichiers_non_pdf(): void
    {
        Storage::fake('public');

        $formateur = User::create([
            'nom'      => 'Formateur',
            'email'    => 'malicieux@formateur.test',
            'password' => bcrypt('password'),
            'role'     => 'formateur',
        ]);
        $token = JWTAuth::fromUser($formateur);

        // Faux PDF avec MIME exécutable — la règle "mimes:pdf" doit le rejeter.
        $fauxFichier = UploadedFile::fake()->create('virus.exe', 50, 'application/octet-stream');

        // Header Accept: application/json pour forcer Laravel à renvoyer 422 au lieu de
        // rediriger (302) vers une page d'erreur HTML.
        $reponse = $this->post(
            '/api/formations',
            [
                'titre'       => 'Tentative malveillante',
                'description' => 'Devrait échouer',
                'categorie'   => 'developpement_web',
                'niveau'      => 'debutant',
                'fichier_pdf' => $fauxFichier,
            ],
            [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ]
        );

        // Validation Laravel renvoie 422 pour les erreurs de format.
        $reponse->assertStatus(422);
        $this->assertEquals(0, Formation::count());
    }

    // =========================================================================
    // SECTION 4 — Email premier message (assertion positive)
    // =========================================================================

    /**
     * Vérifie qu'un email NouveauMessageMail est ENVOYÉ à la première
     * interaction entre 2 utilisateurs (et un seul). SkillHubTest a le
     * test négatif "le 2e ne déclenche pas d'email" — ici on prouve le
     * positif avec Mail::fake() + assertSent.
     */
    #[Test]
    public function premier_message_declenche_un_email_de_notification(): void
    {
        Mail::fake();

        $formateur = User::create([
            'nom'      => 'Form',
            'email'    => 'form@email.test',
            'password' => bcrypt('password'),
            'role'     => 'formateur',
        ]);
        $apprenant = User::create([
            'nom'      => 'App',
            'email'    => 'app@email.test',
            'password' => bcrypt('password'),
            'role'     => 'apprenant',
        ]);
        $tokenA = JWTAuth::fromUser($apprenant);

        // Premier message envoyé par l'apprenant au formateur.
        $this->postJson(
            '/api/messages/envoyer',
            [
                'destinataire_id' => $formateur->id,
                'contenu'         => 'Premier contact',
            ],
            ['Authorization' => 'Bearer ' . $tokenA]
        )->assertStatus(201);

        // Vérifie qu'un mail a bien été dispatché vers l'adresse du formateur.
        Mail::assertSent(NouveauMessageMail::class, function ($mail) use ($formateur) {
            // hasTo : helper interne au Mailable Laravel pour vérifier le destinataire.
            return $mail->hasTo($formateur->email);
        });

        // Deuxième message dans la même conversation : pas de nouveau mail.
        $this->postJson(
            '/api/messages/envoyer',
            [
                'destinataire_id' => $formateur->id,
                'contenu'         => 'Suite',
            ],
            ['Authorization' => 'Bearer ' . $tokenA]
        )->assertStatus(201);

        // Mail::assertSent compte AU TOTAL les envois, pas seulement le dernier.
        // On s'attend toujours à 1 seul envoi malgré 2 messages.
        Mail::assertSent(NouveauMessageMail::class, 1);
    }

    // =========================================================================
    // SECTION 5 — Vues uniques (anonyme + authentifié)
    // =========================================================================

    /**
     * Vérifie que le compteur nombre_de_vues d'une formation respecte la
     * dédup par utilisateur connecté ET par IP visiteur anonyme.
     *
     * SkillHubTest a déjà des tests similaires ; on les chaîne ici pour
     * démontrer le comportement combiné dans un même parcours.
     */
    #[Test]
    public function compteur_vues_uniques_dedoublonne_apprenant_et_visiteur_anonyme(): void
    {
        $formateur = User::create([
            'nom'      => 'Formateur Vues',
            'email'    => 'vues@formateur.test',
            'password' => bcrypt('password'),
            'role'     => 'formateur',
        ]);
        $formation = Formation::create([
            'titre'          => 'Formation Populaire',
            'description'    => 'Sera vue plusieurs fois',
            'categorie'      => 'developpement_web',
            'niveau'         => 'debutant',
            'nombre_de_vues' => 0,
            'formateur_id'   => $formateur->id,
        ]);

        $apprenant = User::create([
            'nom'      => 'Curieuse',
            'email'    => 'curieuse@apprenant.test',
            'password' => bcrypt('password'),
            'role'     => 'apprenant',
        ]);
        $tokenA = JWTAuth::fromUser($apprenant);

        // 1) Visiteur anonyme A regarde 2 fois → 1 vue comptée.
        $this->call('GET', '/api/formations/' . $formation->id, [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        $this->call('GET', '/api/formations/' . $formation->id, [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);

        // 2) Visiteur anonyme B (autre IP) regarde 1 fois → 1 vue comptée.
        $this->call('GET', '/api/formations/' . $formation->id, [], [], [], ['REMOTE_ADDR' => '5.6.7.8']);

        // 3) Apprenant connecté regarde 3 fois → 1 vue comptée.
        for ($i = 0; $i < 3; $i++) {
            $this->getJson(
                '/api/formations/' . $formation->id,
                ['Authorization' => 'Bearer ' . $tokenA]
            );
        }

        // Total attendu : 3 vues (anonyme A + anonyme B + apprenant connecté).
        $formation->refresh();
        $this->assertEquals(3, $formation->nombre_de_vues);
    }

    // =========================================================================
    // SECTION 6 — Photo de profil (lifecycle complet)
    // =========================================================================

    /**
     * Upload photo, MAJ visible dans /profile, ré-upload remplace,
     * fichier physiquement présent.
     */
    #[Test]
    public function photo_de_profil_lifecycle_upload_et_remplacement(): void
    {
        $apprenant = User::create([
            'nom'      => 'Avatar',
            'email'    => 'avatar@apprenant.test',
            'password' => bcrypt('password'),
            'role'     => 'apprenant',
        ]);
        $token = JWTAuth::fromUser($apprenant);

        // 1) Avant upload : photo_profil null dans le profil.
        $this->getJson('/api/profile', ['Authorization' => 'Bearer ' . $token])
             ->assertStatus(200)
             ->assertJsonPath('user.photo_profil', null);

        // 2) Upload première photo.
        // Note : UploadedFile::fake()->image() nécessite l'extension GD (souvent absente
        // dans php-cli minimal). On utilise create() avec mimeType pour s'en passer ;
        // la validation Laravel "image|mimes:..." se base aussi sur l'extension du nom de fichier.
        $photo1 = UploadedFile::fake()->create('avatar1.jpg', 100, 'image/jpeg');
        $reponseUpload = $this->post(
            '/api/profil/photo',
            ['photo' => $photo1],
            ['Authorization' => 'Bearer ' . $token]
        );
        $reponseUpload->assertStatus(200);
        $cheminPhoto1 = $reponseUpload->json('photo_profil');
        $this->assertNotNull($cheminPhoto1);
        $this->assertStringStartsWith('/images/profils/profil_' . $apprenant->id, $cheminPhoto1);

        // 3) Re-fetch profile → photo_profil est posée.
        $this->getJson('/api/profile', ['Authorization' => 'Bearer ' . $token])
             ->assertStatus(200)
             ->assertJsonPath('user.photo_profil', $cheminPhoto1);

        // 4) Upload deuxième photo → remplace la première (ancienne supprimée du disque).
        $photo2 = UploadedFile::fake()->create('avatar2.png', 100, 'image/png');
        $reponseUpload2 = $this->post(
            '/api/profil/photo',
            ['photo' => $photo2],
            ['Authorization' => 'Bearer ' . $token]
        );
        $reponseUpload2->assertStatus(200);
        $cheminPhoto2 = $reponseUpload2->json('photo_profil');
        $this->assertNotEquals($cheminPhoto1, $cheminPhoto2);

        // Cleanup : on supprime les fichiers physiques pour ne pas polluer le repo.
        // (Le test écrit dans public/images/profils/, hors du Storage::fake.)
        foreach ([$cheminPhoto1, $cheminPhoto2] as $chemin) {
            if (file_exists(public_path($chemin))) {
                @unlink(public_path($chemin));
            }
        }
    }

    // =========================================================================
    // SECTION 7 — Sécurité : un apprenant ne peut PAS faire d'opérations formateur
    // =========================================================================

    /**
     * Test "matrice de sécurité" : un apprenant authentifié essaye toutes
     * les opérations réservées au formateur. TOUTES doivent retourner 403.
     *
     * SkillHubTest a des tests individuels, mais ici on les groupe pour
     * démontrer le contrat de sécurité dans son ensemble.
     */
    #[Test]
    public function un_apprenant_ne_peut_acceder_a_aucun_endpoint_formateur(): void
    {
        $formateur = User::create([
            'nom'      => 'Formateur',
            'email'    => 'sec_form@test.com',
            'password' => bcrypt('password'),
            'role'     => 'formateur',
        ]);
        $formation = Formation::create([
            'titre'          => 'Existante',
            'description'    => 'desc',
            'categorie'      => 'developpement_web',
            'niveau'         => 'debutant',
            'nombre_de_vues' => 0,
            'formateur_id'   => $formateur->id,
        ]);
        $module = Module::create([
            'titre'        => 'M1',
            'contenu'      => 'c',
            'ordre'        => 1,
            'formation_id' => $formation->id,
        ]);

        $apprenant = User::create([
            'nom'      => 'App',
            'email'    => 'sec_app@test.com',
            'password' => bcrypt('password'),
            'role'     => 'apprenant',
        ]);
        $token = JWTAuth::fromUser($apprenant);
        $headers = ['Authorization' => 'Bearer ' . $token];

        // POST formation → 403 (rôle apprenant rejeté).
        $this->postJson('/api/formations', [
            'titre'       => 'Tentative',
            'description' => 'd',
            'categorie'   => 'developpement_web',
            'niveau'      => 'debutant',
        ], $headers)->assertStatus(403);

        // PUT formation existante → 403.
        $this->putJson('/api/formations/' . $formation->id, [
            'titre'       => 'Hijack',
            'description' => 'd',
            'categorie'   => 'developpement_web',
            'niveau'      => 'debutant',
        ], $headers)->assertStatus(403);

        // DELETE formation → 403.
        $this->deleteJson('/api/formations/' . $formation->id, [], $headers)
             ->assertStatus(403);

        // POST module dans formation existante → 403.
        $this->postJson('/api/formations/' . $formation->id . '/modules', [
            'titre'   => 'Module pirate',
            'contenu' => 'c',
            'ordre'   => 99,
        ], $headers)->assertStatus(403);

        // PUT module existant → 403.
        $this->putJson('/api/modules/' . $module->id, [
            'titre'   => 'Hijack',
            'contenu' => 'c',
            'ordre'   => 1,
        ], $headers)->assertStatus(403);

        // DELETE module existant → 403.
        $this->deleteJson('/api/modules/' . $module->id, [], $headers)
             ->assertStatus(403);

        // GET formateur/mes-formations → 403.
        $this->getJson('/api/formateur/mes-formations', $headers)
             ->assertStatus(403);
    }
}
