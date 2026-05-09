package com.example.auth.service;

import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.security.KeyPair;
import java.security.PrivateKey;
import java.security.PublicKey;

/**
 * Tests unitaires du {@link RsaKeyService}.
 *
 * Vérifie deux fonctionnalités cryptographiques :
 *   1. Génération de paires de clés RSA + sérialisation Base64.
 *   2. Hachage SHA-256 (utilisé pour fingerprints/empreintes de messages).
 *
 * Pas de mocks : on utilise la JCA réelle (java.security). Les tests sont
 * non-flaky car les algos sont déterministes (sauf la génération de clés
 * qui est aléatoire — d'où le test "pairesDistinctesAChaqueFois").
 */
class RsaKeyServiceTest {

    /** SUT instancié à neuf avant chaque test. */
    private RsaKeyService rsaKeyService;

    /** Initialise le service (pas de dépendance à injecter). */
    @BeforeEach
    void setUp() {
        rsaKeyService = new RsaKeyService();
    }

    // ── generateKeyPair ───────────────────────────────────────────────────────

    /**
     * Génère une paire RSA et vérifie que les deux clés (public + private) sont créées.
     */
    @Test
    void genereUnePaireDeClés() {
        KeyPair keyPair = rsaKeyService.generateKeyPair();
        Assertions.assertNotNull(keyPair);
        Assertions.assertNotNull(keyPair.getPublic());
        Assertions.assertNotNull(keyPair.getPrivate());
    }

    /**
     * Deux générations successives doivent donner deux paires distinctes
     * (sinon les sessions cryptographiques ne seraient pas isolées).
     */
    @Test
    void pairesDistinctesAChaqueFois() {
        KeyPair kp1 = rsaKeyService.generateKeyPair();
        KeyPair kp2 = rsaKeyService.generateKeyPair();
        // Les clés publiques encodées doivent être différentes (probabilité de collision ~ 0).
        Assertions.assertNotEquals(
                rsaKeyService.publicKeyToString(kp1.getPublic()),
                rsaKeyService.publicKeyToString(kp2.getPublic())
        );
    }

    // ── publicKeyToString ─────────────────────────────────────────────────────

    /**
     * La clé publique sérialisée doit être une chaîne non vide (encodée Base64).
     */
    @Test
    void convertitClePubliqueEnBase64NonNul() {
        PublicKey pub = rsaKeyService.generateKeyPair().getPublic();
        String encoded = rsaKeyService.publicKeyToString(pub);
        Assertions.assertNotNull(encoded);
        Assertions.assertFalse(encoded.isBlank());
    }

    /**
     * La chaîne retournée doit être du Base64 valide (decoder ne lève pas).
     */
    @Test
    void base64ClePubliqueEstDecodable() {
        PublicKey pub = rsaKeyService.generateKeyPair().getPublic();
        String encoded = rsaKeyService.publicKeyToString(pub);
        // Si la chaîne n'est pas Base64 valide, decode() lève IllegalArgumentException.
        byte[] decoded = java.util.Base64.getDecoder().decode(encoded);
        Assertions.assertTrue(decoded.length > 0);
    }

    // ── privateKeyToString ────────────────────────────────────────────────────

    /**
     * Idem pour la clé privée : sérialisation Base64 non vide.
     */
    @Test
    void convertitClePriveeEnBase64NonNul() {
        PrivateKey priv = rsaKeyService.generateKeyPair().getPrivate();
        String encoded = rsaKeyService.privateKeyToString(priv);
        Assertions.assertNotNull(encoded);
        Assertions.assertFalse(encoded.isBlank());
    }

    /**
     * La clé privée Base64 doit être décodable (round-trip OK).
     */
    @Test
    void base64ClePriveeEstDecodable() {
        PrivateKey priv = rsaKeyService.generateKeyPair().getPrivate();
        String encoded = rsaKeyService.privateKeyToString(priv);
        byte[] decoded = java.util.Base64.getDecoder().decode(encoded);
        Assertions.assertTrue(decoded.length > 0);
    }

    // ── hashSha256 ────────────────────────────────────────────────────────────

    /**
     * Le hash d'une chaîne arbitraire doit être non vide.
     */
    @Test
    void hashSha256NonNul() {
        String hash = rsaKeyService.hashSha256("message de test");
        Assertions.assertNotNull(hash);
        Assertions.assertFalse(hash.isBlank());
    }

    /**
     * SHA-256 produit toujours 256 bits = 32 octets = 64 caractères hex.
     * Si ce test casse, c'est qu'on a accidentellement changé d'algo (ex: SHA-1 ou SHA-512).
     */
    @Test
    void hashSha256Longueur64() {
        // SHA-256 = 256 bits = 32 octets = 64 caractères hex.
        String hash = rsaKeyService.hashSha256("test");
        Assertions.assertEquals(64, hash.length());
    }

    /**
     * Propriété fondamentale d'un hash : déterministe (même entrée → même sortie).
     */
    @Test
    void hashSha256Deterministe() {
        String h1 = rsaKeyService.hashSha256("meme-message");
        String h2 = rsaKeyService.hashSha256("meme-message");
        Assertions.assertEquals(h1, h2);
    }

    /**
     * Deux entrées distinctes doivent donner deux hashes distincts (anti-collision).
     */
    @Test
    void hashSha256DifferentPourMessagesDistincts() {
        String h1 = rsaKeyService.hashSha256("messageA");
        String h2 = rsaKeyService.hashSha256("messageB");
        Assertions.assertNotEquals(h1, h2);
    }

    /**
     * Vérifie le format hex lowercase (regex strict) — important pour
     * la compatibilité avec d'autres systèmes qui consomment ce hash.
     */
    @Test
    void hashSha256FormatHexValide() {
        String actual = rsaKeyService.hashSha256("abc");
        Assertions.assertTrue(actual.matches("[0-9a-f]{64}"),
                "Le hash doit être en hexadécimal lowercase sur 64 caractères");
    }
}
