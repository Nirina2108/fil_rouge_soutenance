package com.example.auth.service;

import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.test.util.ReflectionTestUtils;

/**
 * Tests unitaires du {@link JwtService}.
 *
 * Vérifie la chaîne complète JWT (HMAC-SHA256) :
 *   - génération d'un token signé contenant l'email du user.
 *   - extraction de l'email depuis un token signé.
 *   - validation de signature et de date d'expiration.
 *
 * Pas de @SpringBootTest : on instancie le service directement et on injecte
 * les valeurs des @Value (jwtSecret, jwtExpiration) via ReflectionTestUtils.
 * Plus rapide qu'un boot complet du contexte Spring.
 */
class JwtServiceTest {

    /** SUT — instancié à neuf avant chaque test. */
    private JwtService jwtService;

    /** Secret HMAC suffisamment long (>= 32 chars) pour HS256. */
    private static final String SECRET =
            "test-secret-key-suffisamment-longue-pour-hmac-sha256-minimum-32-chars";

    /** Durée de validité = 1h, exprimée en millisecondes (format Spring standard). */
    private static final long EXPIRATION = 3_600_000L; // 1 heure en ms

    /**
     * Avant chaque test : nouvelle instance du service avec secret et durée injectés.
     */
    @BeforeEach
    void setUp() {
        jwtService = new JwtService();
        ReflectionTestUtils.setField(jwtService, "jwtSecret", SECRET);
        ReflectionTestUtils.setField(jwtService, "jwtExpiration", EXPIRATION);
    }

    // ── generateToken ─────────────────────────────────────────────────────────

    /**
     * La génération doit retourner une chaîne non vide (header.payload.signature).
     */
    @Test
    void genereTokenNonNul() {
        String token = jwtService.generateToken("user@test.com");
        Assertions.assertNotNull(token);
        // !isBlank() = pas null + pas vide + pas que des espaces.
        Assertions.assertFalse(token.isBlank());
    }

    /**
     * Deux tokens pour des emails différents doivent différer (sinon les sessions
     * d'utilisateurs distincts ne pourraient pas être distinguées).
     */
    @Test
    void genereTokenDistinctPourEmailsDifferents() {
        String t1 = jwtService.generateToken("alice@test.com");
        String t2 = jwtService.generateToken("bob@test.com");
        Assertions.assertNotEquals(t1, t2);
    }

    // ── extractEmail ──────────────────────────────────────────────────────────

    /**
     * Round-trip : email injecté dans le payload doit être récupérable tel quel.
     */
    @Test
    void extraitEmailDepuisToken() {
        String token = jwtService.generateToken("user@test.com");
        String email = jwtService.extractEmail(token);
        Assertions.assertEquals("user@test.com", email);
    }

    /**
     * Variation du test précédent avec un autre email pour s'assurer que le payload
     * n'est pas accidentellement hardcodé quelque part.
     */
    @Test
    void extraitEmailCorrectementApresGeneration() {
        String email = "alice@example.com";
        String token = jwtService.generateToken(email);
        Assertions.assertEquals(email, jwtService.extractEmail(token));
    }

    // ── isTokenValid ──────────────────────────────────────────────────────────

    /**
     * Token fraîchement généré avec la bonne clé → valide.
     */
    @Test
    void tokenValideAvecBonneCle() {
        String token = jwtService.generateToken("user@test.com");
        Assertions.assertTrue(jwtService.isTokenValid(token));
    }

    /**
     * Token au format JWT mais avec signature falsifiée → invalide.
     * Ce cas couvre les attaques de type "alter & re-sign" sans la clé.
     */
    @Test
    void tokenInvalideAvecJetonFalsifie() {
        Assertions.assertFalse(jwtService.isTokenValid("header.payload.signature-fausse"));
    }

    /**
     * Chaîne vide → invalide. Couvre le cas où le frontend transmet un header malformé.
     */
    @Test
    void tokenInvalideAvecChainVide() {
        Assertions.assertFalse(jwtService.isTokenValid(""));
    }

    /**
     * Token expiré (durée = 0 ms à la génération) → invalide.
     * Vérifie que la date d'expiration est bien contrôlée par le service.
     */
    @Test
    void tokenExpiré() {
        // On crée un service éphémère avec expiration = 0 ms : le token expire dès sa création.
        JwtService expiredService = new JwtService();
        ReflectionTestUtils.setField(expiredService, "jwtSecret", SECRET);
        ReflectionTestUtils.setField(expiredService, "jwtExpiration", 0L);

        String token = expiredService.generateToken("user@test.com");
        Assertions.assertFalse(expiredService.isTokenValid(token));
    }
}
