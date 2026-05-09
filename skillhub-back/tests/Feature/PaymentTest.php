<?php

namespace Tests\Feature;

use App\Models\Formation;
use App\Models\Inscription;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Tests du flow de paiement simulé pour formation payante.
 *
 * Cas couverts :
 *  1. Mot de passe correct + formation payante → 201 + inscription créée
 *  2. Mot de passe incorrect → 401 + Payment statut=echec en base (audit)
 *  3. Mot de passe incorrect → pas d'inscription créée
 *  4. Sans token JWT → 401
 *  5. Formation gratuite (prix=0) → 400 (l'apprenant doit utiliser l'inscription standard)
 *  6. Formation introuvable → 404
 *  7. Déjà inscrit → 400 (idempotence)
 *  8. Formateur tente de payer → 403 (rôle invalide)
 *  9. Anti price-tampering : montant côté serveur lu depuis BDD, pas le payload
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/payments/confirmer';
    private const MOT_DE_PASSE_VALIDE = 'password123';

    /**
     * Helper : crée un user du rôle donné avec mot de passe connu, retourne {user, token}.
     */
    private function creerUtilisateur(string $role): array
    {
        $user = User::create([
            'nom' => 'Test ' . ucfirst($role),
            'email' => $role . '_' . uniqid() . '@test.com',
            'password' => bcrypt(self::MOT_DE_PASSE_VALIDE),
            'role' => $role,
        ]);

        return ['user' => $user, 'token' => JWTAuth::fromUser($user)];
    }

    /**
     * Helper : crée une formation avec un prix donné (1500 par défaut).
     */
    private function creerFormation(User $formateur, float $prix = 1500): Formation
    {
        return Formation::create([
            'titre' => 'Formation Test',
            'description' => 'Description',
            'categorie' => 'developpement_web',
            'niveau' => 'debutant',
            'prix' => $prix,
            'nombre_de_vues' => 0,
            'formateur_id' => $formateur->id,
        ]);
    }

    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    // ─── Cas nominal ──────────────────────────────────────────────────────────

    /**
     * Cas 1 : flow nominal — mot de passe correct + formation payante.
     */
    #[Test]
    public function paiement_reussi_cree_inscription_et_payment_record(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur, 1500);
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->postJson(self::URL, [
            'formation_id' => $formation->id,
            'mot_de_passe' => self::MOT_DE_PASSE_VALIDE,
        ], $this->headers($token));

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'payment', 'inscription', 'montant_paye'])
            ->assertJsonPath('payment.statut', 'reussi');
        // assertJsonPath compare en strict ; PHP serialise 1500.0 en int 1500.
        // On passe par (float) cast pour comparer la valeur indépendamment du type.
        $this->assertEquals(1500.0, (float) $response->json('montant_paye'));

        // Inscription créée en base.
        $this->assertDatabaseHas('inscriptions', [
            'utilisateur_id' => $apprenant->id,
            'formation_id' => $formation->id,
            'progression' => 0,
        ]);

        // Payment trace en base avec statut reussi.
        $this->assertDatabaseHas('payments', [
            'user_id' => $apprenant->id,
            'formation_id' => $formation->id,
            'statut' => 'reussi',
        ]);
    }

    // ─── Sécurité : ré-authentification ───────────────────────────────────────

    /**
     * Cas 2 : mot de passe incorrect → 401 (alignement avec auth JWT échouée).
     */
    #[Test]
    public function paiement_avec_mauvais_mot_de_passe_retourne_401(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->postJson(self::URL, [
            'formation_id' => $formation->id,
            'mot_de_passe' => 'mauvaisPassword',
        ], $this->headers($token));

        $response->assertStatus(401)
            ->assertJsonPath('erreur', 'mot_de_passe_incorrect');
    }

    /**
     * Cas 3 : mot de passe incorrect → AUCUNE inscription créée.
     * Garantit qu'aucun pirate n'inscrit à la place du user en bypassant la re-auth.
     */
    #[Test]
    public function paiement_mauvais_mot_de_passe_ne_cree_pas_inscription(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');

        $this->postJson(self::URL, [
            'formation_id' => $formation->id,
            'mot_de_passe' => 'mauvais',
        ], $this->headers($token));

        // Aucune inscription créée.
        $this->assertEquals(0, Inscription::count());
        // Trace d'échec en base (forensic).
        $this->assertDatabaseHas('payments', [
            'user_id' => $apprenant->id,
            'formation_id' => $formation->id,
            'statut' => 'echec',
        ]);
    }

    /**
     * Cas 4 : sans token JWT → 401 (middleware auth:api).
     */
    #[Test]
    public function paiement_sans_token_retourne_401(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);

        $response = $this->postJson(self::URL, [
            'formation_id' => $formation->id,
            'mot_de_passe' => self::MOT_DE_PASSE_VALIDE,
        ]);

        $response->assertStatus(401);
    }

    // ─── Cas métier ───────────────────────────────────────────────────────────

    /**
     * Cas 5 : formation gratuite → 400 (l'apprenant doit utiliser l'endpoint standard).
     */
    #[Test]
    public function paiement_formation_gratuite_retourne_400(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur, 0);   // prix = 0
        ['token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->postJson(self::URL, [
            'formation_id' => $formation->id,
            'mot_de_passe' => self::MOT_DE_PASSE_VALIDE,
        ], $this->headers($token));

        $response->assertStatus(400)
            ->assertJsonPath('erreur', 'formation_gratuite');
    }

    /**
     * Cas 6 : formation introuvable → 404.
     */
    #[Test]
    public function paiement_formation_introuvable_retourne_404(): void
    {
        ['token' => $token] = $this->creerUtilisateur('apprenant');

        $response = $this->postJson(self::URL, [
            'formation_id' => 99999,
            'mot_de_passe' => self::MOT_DE_PASSE_VALIDE,
        ], $this->headers($token));

        $response->assertStatus(404)
            ->assertJsonPath('erreur', 'formation_introuvable');
    }

    /**
     * Cas 7 : déjà inscrit (idempotence).
     */
    #[Test]
    public function paiement_deja_inscrit_retourne_400(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur);
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');

        // 1er paiement : OK.
        $this->postJson(self::URL, [
            'formation_id' => $formation->id,
            'mot_de_passe' => self::MOT_DE_PASSE_VALIDE,
        ], $this->headers($token))->assertStatus(201);

        // 2e paiement : 400 (déjà inscrit).
        $response = $this->postJson(self::URL, [
            'formation_id' => $formation->id,
            'mot_de_passe' => self::MOT_DE_PASSE_VALIDE,
        ], $this->headers($token));

        $response->assertStatus(400)
            ->assertJsonPath('erreur', 'deja_inscrit');
    }

    /**
     * Cas 8 : un formateur tente de payer une formation → 403.
     */
    #[Test]
    public function paiement_par_formateur_retourne_403(): void
    {
        ['user' => $formateurAuteur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateurAuteur);

        // Un autre formateur (qui n'est pas l'auteur) tente de payer.
        ['token' => $tokenAutreFormateur] = $this->creerUtilisateur('formateur');

        $response = $this->postJson(self::URL, [
            'formation_id' => $formation->id,
            'mot_de_passe' => self::MOT_DE_PASSE_VALIDE,
        ], $this->headers($tokenAutreFormateur));

        $response->assertStatus(403)
            ->assertJsonPath('erreur', 'role_invalide');
    }

    // ─── Sécurité : anti price-tampering ──────────────────────────────────────

    /**
     * Cas 9 : SI un attaquant envoyait un montant dans le payload, le serveur
     * doit IGNORER cette valeur et utiliser celle de la BDD.
     *
     * Test : envoi d'un payload avec montant=1 (faux) → le payment record en base
     * a quand même montant=1500 (lu depuis formation.prix).
     */
    #[Test]
    public function paiement_ignore_le_montant_envoye_par_le_client(): void
    {
        ['user' => $formateur] = $this->creerUtilisateur('formateur');
        $formation = $this->creerFormation($formateur, 1500);   // vrai prix
        ['user' => $apprenant, 'token' => $token] = $this->creerUtilisateur('apprenant');

        // Tentative malicieuse : on glisse "montant" dans le payload.
        $response = $this->postJson(self::URL, [
            'formation_id' => $formation->id,
            'mot_de_passe' => self::MOT_DE_PASSE_VALIDE,
            'montant' => 1,   // tentative de price tampering
        ], $this->headers($token));

        $response->assertStatus(201);
        // Le serveur a réellement enregistré 1500 (la vraie valeur en BDD).
        $payment = Payment::where('user_id', $apprenant->id)->first();
        $this->assertEquals(1500.0, (float) $payment->montant);
    }
}
