package com.example.auth.service;

import com.example.auth.entity.User;
import com.example.auth.repository.UserRepository;
import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.InjectMocks;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;

import java.time.LocalDateTime;
import java.util.Optional;

import static org.mockito.Mockito.when;

/**
 * Tests unitaires du {@link TokenService}.
 *
 * Vérifie la validation du header Authorization Bearer côté serveur :
 *   - parsing du préfixe "Bearer ",
 *   - lookup du token en base via UserRepository,
 *   - vérification de la date d'expiration (token_expires_at).
 *
 * Différent de JwtService : ici on teste le mode "session token persistant"
 * (token UUID stocké en colonne user.token), pas les JWT signés.
 */
@ExtendWith(MockitoExtension.class)
class TokenServiceTest {

    /** Mock du repository : on simule findByToken avec différents cas. */
    @Mock
    private UserRepository userRepository;

    /** SUT — Mockito injecte le repo dans le constructeur. */
    @InjectMocks
    private TokenService tokenService;

    /** User de référence avec un token valide pour 30 min. */
    private User validUser;

    /**
     * Avant chaque test : prépare un user "valide" avec un token frais.
     * Les tests qui veulent simuler un token expiré ré-écrivent tokenExpiresAt.
     */
    @BeforeEach
    void setUp() {
        validUser = new User();
        validUser.setToken("token-valide");
        // Token valable 30 min — assez de marge pour que le test ne flaque pas.
        validUser.setTokenExpiresAt(LocalDateTime.now().plusMinutes(30));
        validUser.setEmail("user@test.com");
    }

    // ── getUserFromToken ──────────────────────────────────────────────────────

    /**
     * Cas nominal : header "Bearer token-valide" + user en base + expiration future
     * → retourne le user.
     */
    @Test
    void retourneUtilisateurAvecTokenValide() {
        when(userRepository.findByToken("token-valide")).thenReturn(Optional.of(validUser));

        User result = tokenService.getUserFromToken("Bearer token-valide");

        Assertions.assertNotNull(result);
        Assertions.assertEquals("user@test.com", result.getEmail());
    }

    /**
     * Header null (pas d'Authorization du tout) → null retourné.
     */
    @Test
    void retourneNullSiHeaderNull() {
        User result = tokenService.getUserFromToken(null);
        Assertions.assertNull(result);
    }

    /**
     * Header présent mais sans préfixe "Bearer " → null
     * (le standard impose le préfixe pour distinguer du Basic auth).
     */
    @Test
    void retourneNullSiHeaderSansBearerPrefix() {
        User result = tokenService.getUserFromToken("token-sans-bearer");
        Assertions.assertNull(result);
    }

    /**
     * Cas dégénéré : "Bearer " avec un token vide → null (pas de match en base).
     */
    @Test
    void retourneNullSiHeaderVideApresBearer() {
        // Le token extrait sera vide, findByToken retourne empty.
        when(userRepository.findByToken("")).thenReturn(Optional.empty());
        User result = tokenService.getUserFromToken("Bearer ");
        Assertions.assertNull(result);
    }

    /**
     * Token bien formé mais inconnu en base → null.
     */
    @Test
    void retourneNullSiTokenInconnu() {
        when(userRepository.findByToken("token-inconnu")).thenReturn(Optional.empty());

        User result = tokenService.getUserFromToken("Bearer token-inconnu");
        Assertions.assertNull(result);
    }

    /**
     * Token trouvé en base mais expiré (date passée) → null.
     * Critique pour la sécurité : un token volé n'est utilisable qu'à durée limitée.
     */
    @Test
    void retourneNullSiTokenExpire() {
        // On force la date d'expiration 10 min dans le passé.
        validUser.setTokenExpiresAt(LocalDateTime.now().minusMinutes(10));
        when(userRepository.findByToken("token-valide")).thenReturn(Optional.of(validUser));

        User result = tokenService.getUserFromToken("Bearer token-valide");
        Assertions.assertNull(result);
    }

    /**
     * Cas limite : tokenExpiresAt null (donnée invalide en DB) → on rejette par sécurité.
     */
    @Test
    void retourneNullSiTokenExpiresAtNull() {
        validUser.setTokenExpiresAt(null);
        when(userRepository.findByToken("token-valide")).thenReturn(Optional.of(validUser));

        User result = tokenService.getUserFromToken("Bearer token-valide");
        Assertions.assertNull(result);
    }

    // ── hasValidFormat ────────────────────────────────────────────────────────

    /**
     * Format valide = "Bearer " suivi d'au moins un caractère.
     */
    @Test
    void formatValideAvecBearer() {
        Assertions.assertTrue(tokenService.hasValidFormat("Bearer abc123"));
    }

    /**
     * Sans le préfixe "Bearer " → format rejeté.
     */
    @Test
    void formatInvalideSansBearer() {
        Assertions.assertFalse(tokenService.hasValidFormat("abc123"));
    }

    /**
     * Header null → format invalide (pas de NPE non plus).
     */
    @Test
    void formatInvalideSiNull() {
        Assertions.assertFalse(tokenService.hasValidFormat(null));
    }

    /**
     * Header vide → format invalide.
     */
    @Test
    void formatInvalideSiChainVide() {
        Assertions.assertFalse(tokenService.hasValidFormat(""));
    }
}
