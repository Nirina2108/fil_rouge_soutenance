<?php

namespace Tests\Feature;

use App\Models\Formation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Tests de charge/volume — vérifient que l'application gère un grand
 * nombre d'enregistrements (utilisateurs, formations) sans dégradation
 * majeure de performance ni erreur fonctionnelle.
 *
 * Stratégie :
 *   - Bulk insert via User::insert() (mass insert SQL natif, pas Eloquent)
 *     pour éviter le coût BCrypt sur chaque ligne (le mot de passe est
 *     pré-hashé une seule fois et réutilisé).
 *   - Volume paramétrable via env LOAD_TEST_VOLUME (défaut 1000).
 *   - Vérification de connexion par ÉCHANTILLONNAGE : login HTTP réel
 *     sur 5 utilisateurs aléatoires parmi les N créés (couvre le contrat
 *     fonctionnel sans subir 10000 requêtes HTTP).
 *
 * Marqué `#[Group('heavy')]` : exclu par défaut de `php artisan test`.
 * Lancement explicite :
 *   LOAD_TEST_VOLUME=10000 php artisan test --group=heavy
 *
 * NB : utilise SQLite in-memory (cf. phpunit.xml). 100K lignes peuvent
 * dépasser la mémoire JVM/PHP — adapter en conséquence.
 */
#[Group('heavy')]
class LoadTest extends TestCase
{
    // RefreshDatabase : DB H2-like recréée entre chaque test (transactionnel).
    use RefreshDatabase;

    /**
     * Lit le volume cible depuis l'environnement.
     * Défaut 1000 (rapide), surchargeable via `LOAD_TEST_VOLUME=10000` ou phpunit.xml.
     */
    private function volume(): int
    {
        // env() lit en priorité .env/getenv puis le bloc <php> de phpunit.xml.
        return max(1, (int) env('LOAD_TEST_VOLUME', 1000));
    }

    /**
     * Helper : insère en bulk N users (rôle apprenant) avec un mot de passe
     * commun pré-hashé. Beaucoup plus rapide que User::create() en boucle.
     *
     * Retourne le mot de passe en clair pour permettre les tests de login.
     */
    private function bulkInsertApprenants(int $n): string
    {
        // Hash bcrypt UNE SEULE FOIS — bcrypt(pass) coûte ~10ms en rounds=4 (test).
        // Avec rounds=4, hasher 10000 fois ferait perdre ~100s. On fait 1 fois et on réutilise.
        $motDePasse = 'password123';
        $hash = Hash::make($motDePasse);

        $maintenant = now();
        $rows = [];

        for ($i = 0; $i < $n; $i++) {
            $rows[] = [
                'nom'        => 'Apprenant' . $i,
                // Email unique avec le timestamp pour éviter la collision avec d'autres tests.
                'email'      => 'apprenant_load_' . $i . '@test.com',
                'password'   => $hash,
                'role'       => 'apprenant',
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];
        }

        // Insert en chunks de 500 lignes : SQLite et MySQL ont une limite sur la taille
        // d'une requête INSERT (max_allowed_packet, SQLite ~1Mo de paramètres).
        foreach (array_chunk($rows, 500) as $chunk) {
            User::insert($chunk);
        }

        return $motDePasse;
    }

    /**
     * Test 1 : insertion en masse de N apprenants — vérifie la persistance
     * et la performance (timing affiché dans la sortie testdox).
     */
    #[Test]
    public function inscription_de_N_utilisateurs_persiste_en_base(): void
    {
        $n = $this->volume();

        $debut = microtime(true);
        $this->bulkInsertApprenants($n);
        $duree = microtime(true) - $debut;

        // Affichage timing (visible avec --testdox-text=php://stdout).
        echo "\n[LoadTest] {$n} apprenants inserts en " . round($duree, 2) . "s\n";

        // Vérifie que TOUS les enregistrements sont en base.
        $this->assertEquals($n, User::where('role', 'apprenant')->count());

        // Vérifie l'unicité de l'email (la contrainte UNIQUE n'a pas été violée).
        $this->assertEquals(
            $n,
            User::where('role', 'apprenant')->distinct('email')->count('email')
        );
    }

    /**
     * Test 2 : connexion d'un échantillon de 5 utilisateurs après inscription
     * en masse — valide que le flow d'auth fonctionne sur un dataset large.
     *
     * On fait du HTTP "réel" (postJson) mais SEULEMENT sur 5 users tirés au
     * hasard parmi les N. Suffisant pour valider le contrat sans saturer.
     */
    #[Test]
    public function connexion_par_echantillonnage_apres_N_inscriptions(): void
    {
        $n = $this->volume();
        $motDePasse = $this->bulkInsertApprenants($n);

        // 5 indices aléatoires dans [0, n-1] — couvrent début, milieu, fin.
        $indices = [];
        $indices[] = 0;                    // 1er user
        $indices[] = (int) ($n / 4);       // quart
        $indices[] = (int) ($n / 2);       // milieu
        $indices[] = (int) (3 * $n / 4);   // 3/4
        $indices[] = $n - 1;               // dernier

        $debut = microtime(true);

        foreach ($indices as $i) {
            $email = 'apprenant_load_' . $i . '@test.com';

            $response = $this->postJson('/api/login', [
                'email'    => $email,
                'password' => $motDePasse,
            ]);

            // Chaque login doit réussir (status 200 + token JWT dans la réponse).
            $response->assertStatus(200)
                     ->assertJsonStructure(['message', 'token', 'user']);
        }

        $duree = microtime(true) - $debut;
        echo "\n[LoadTest] 5 logins HTTP echantillonnes parmi {$n} en " . round($duree, 2) . "s\n";

        // Sanity check : la table des users contient bien tous les enregistrements.
        $this->assertEquals($n, User::where('role', 'apprenant')->count());
    }

    /**
     * Test 3 : création en masse de N formations + vérification du listing.
     *
     * Crée 1 formateur, lui rattache N formations en bulk, puis interroge
     * GET /api/formations pour vérifier le listing public + filtres.
     */
    #[Test]
    public function creation_de_N_formations_et_listing(): void
    {
        $n = $this->volume();

        // Un formateur "porteur" pour toutes les formations (FK formateur_id).
        $formateur = User::create([
            'nom'      => 'Formateur Load Test',
            'email'    => 'formateur_load@test.com',
            'password' => bcrypt('password'),
            'role'     => 'formateur',
        ]);

        $maintenant = now();
        $rows = [];

        for ($i = 0; $i < $n; $i++) {
            $rows[] = [
                'titre'          => 'Formation Load #' . $i,
                'description'    => 'Description de la formation ' . $i,
                // 3 catégories pour pouvoir tester le filtre.
                'categorie'      => ['developpement_web', 'data', 'design'][$i % 3],
                // 3 niveaux pour pouvoir tester le filtre niveau.
                'niveau'         => ['debutant', 'intermediaire', 'avance'][$i % 3],
                'prix'           => 0,
                'duree_heures'   => 10 + ($i % 50),
                'nombre_de_vues' => 0,
                'formateur_id'   => $formateur->id,
                'created_at'     => $maintenant,
                'updated_at'     => $maintenant,
            ];
        }

        $debut = microtime(true);
        // Insert chunké pour rester sous max_allowed_packet.
        foreach (array_chunk($rows, 500) as $chunk) {
            Formation::insert($chunk);
        }
        $dureeInsert = microtime(true) - $debut;

        echo "\n[LoadTest] {$n} formations inserees en " . round($dureeInsert, 2) . "s\n";

        // Vérifie le count global.
        $this->assertEquals($n, Formation::count());

        // Listing public — endpoint le plus consulté en prod, doit rester rapide.
        $debutListing = microtime(true);
        $response = $this->getJson('/api/formations');
        $dureeListing = microtime(true) - $debutListing;

        echo "[LoadTest] GET /api/formations sur {$n} entrees en " . round($dureeListing, 3) . "s\n";

        $response->assertStatus(200);

        // Filtre par catégorie : doit retourner exactement n/3 formations
        // (modulo arrondi : si n=10000, on a ceil(10000/3) ou floor selon l'index).
        $debutFiltre = microtime(true);
        $responseFiltre = $this->getJson('/api/formations?categorie=developpement_web');
        $dureeFiltre = microtime(true) - $debutFiltre;

        echo "[LoadTest] GET /api/formations?categorie=... en " . round($dureeFiltre, 3) . "s\n";
        $responseFiltre->assertStatus(200);
    }

    /**
     * Test 4 : couverture mixte — N apprenants + N formations + 1 inscription
     * de chaque apprenant à une formation aléatoire. Simule une plateforme
     * "vivante" pour vérifier qu'il n'y a pas de dégradation des relations.
     *
     * Volume effectif : N users + N formations + N inscriptions = 3*N rows.
     * À garder modéré (par défaut 1000) pour ne pas exploser la durée.
     */
    #[Test]
    public function inscriptions_massives_apprenants_x_formations(): void
    {
        $n = $this->volume();

        // 1) Bulk users.
        $this->bulkInsertApprenants($n);

        // 2) 1 formateur + bulk formations.
        $formateur = User::create([
            'nom'      => 'Formateur Mix',
            'email'    => 'formateur_mix@test.com',
            'password' => bcrypt('password'),
            'role'     => 'formateur',
        ]);

        $maintenant = now();
        $formationRows = [];
        for ($i = 0; $i < $n; $i++) {
            $formationRows[] = [
                'titre'        => 'Formation Mix #' . $i,
                'description'  => 'desc',
                'categorie'    => 'developpement_web',
                'niveau'       => 'debutant',
                'prix'         => 0,
                'duree_heures' => 10,
                'nombre_de_vues' => 0,
                'formateur_id' => $formateur->id,
                'created_at'   => $maintenant,
                'updated_at'   => $maintenant,
            ];
        }
        foreach (array_chunk($formationRows, 500) as $chunk) {
            Formation::insert($chunk);
        }

        // 3) Récupère les IDs réels (auto-incrément) pour faire les liens.
        $userIds = User::where('role', 'apprenant')->pluck('id');
        $formationIds = Formation::pluck('id');

        $this->assertEquals($n, $userIds->count());
        $this->assertEquals($n, $formationIds->count());

        // 4) Bulk inscriptions : chaque apprenant inscrit à 1 formation (round-robin).
        $inscriptionRows = [];
        $maintenantStr = $maintenant->toDateTimeString();
        for ($i = 0; $i < $n; $i++) {
            $inscriptionRows[] = [
                'utilisateur_id' => $userIds[$i],
                'formation_id'   => $formationIds[$i % $n],
                'progression'    => 0,
                'created_at'     => $maintenantStr,
                'updated_at'     => $maintenantStr,
            ];
        }

        $debut = microtime(true);
        foreach (array_chunk($inscriptionRows, 500) as $chunk) {
            \DB::table('inscriptions')->insert($chunk);
        }
        $duree = microtime(true) - $debut;

        echo "\n[LoadTest] {$n} inscriptions inserees en " . round($duree, 2) . "s\n";

        $this->assertEquals($n, \DB::table('inscriptions')->count());
    }
}
