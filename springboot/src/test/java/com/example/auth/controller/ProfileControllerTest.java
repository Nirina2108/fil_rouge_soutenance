package com.example.auth.controller;

import com.example.auth.entity.User;
import com.example.auth.repository.UserRepository;
import com.example.auth.service.TokenService;
import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.InjectMocks;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.test.util.ReflectionTestUtils;

import java.time.LocalDateTime;
import java.util.Map;

import static org.mockito.ArgumentMatchers.any;
import static org.mockito.Mockito.when;

/**
 * Tests unitaires du {@link ProfileController}.
 *
 * Couvre les deux endpoints :
 *   - GET /api/profile/me : retourne le profil de l'utilisateur connecté.
 *   - POST /api/profile/avatar : upload d'une photo de profil.
 *
 * Les vérifications portent sur :
 *   - l'authentification (token Bearer valide ou non)
 *   - le format du fichier (image, non vide)
 *   - la cohérence du nom de fichier généré (extension préservée).
 *
 * On utilise ReflectionTestUtils pour injecter les @Value uploadDir et baseUrl
 * (qui sont normalement résolues par Spring depuis application.properties).
 */
@ExtendWith(MockitoExtension.class)
class ProfileControllerTest {

    /** Mock du repository utilisateur (save retourne ce qu'on veut). */
    @Mock
    private UserRepository userRepository;

    /** Mock du validateur de token : on simule le cas valide / invalide. */
    @Mock
    private TokenService tokenService;

    /** SUT — Mockito injecte les mocks dans le constructeur. */
    @InjectMocks
    private ProfileController profileController;

    /** Utilisateur de référence utilisé dans la majorité des tests. */
    private User user;

    /**
     * Avant chaque test : on injecte les @Value via reflection (uploadDir, baseUrl)
     * et on prépare un user "Alice" avec un avatar déjà existant (simule un upload
     * précédent qui devrait être remplacé).
     */
    @BeforeEach
    void setUp() {
        // ReflectionTestUtils : injecte les valeurs des @Value sans bootstrap Spring.
        ReflectionTestUtils.setField(profileController, "uploadDir", "target/test-uploads");
        ReflectionTestUtils.setField(profileController, "baseUrl", "http://localhost:8000");

        user = new User();
        user.setId(1L);
        user.setName("Alice");
        user.setEmail("alice@test.com");
        user.setAvatar("http://localhost:8000/api/profile/avatar/old.jpg");
        user.setCreatedAt(LocalDateTime.now());
    }

    // ── getProfile ────────────────────────────────────────────────────────────

    /**
     * Cas nominal : token valide → retour du profil avec name/email du user mocké.
     */
    @Test
    void retourneProfilAvecTokenValide() {
        when(tokenService.getUserFromToken("Bearer token")).thenReturn(user);

        Map<String, Object> result = profileController.getProfile("Bearer token");

        Assertions.assertFalse(result.containsKey("error"));
        Assertions.assertEquals("Alice", result.get("name"));
        Assertions.assertEquals("alice@test.com", result.get("email"));
    }

    /**
     * Header Authorization absent (null) → erreur retournée.
     */
    @Test
    void retourneErreurProfilSiTokenNull() {
        when(tokenService.getUserFromToken(null)).thenReturn(null);

        Map<String, Object> result = profileController.getProfile(null);

        Assertions.assertTrue(result.containsKey("error"));
    }

    /**
     * Header présent mais token invalide → erreur retournée.
     */
    @Test
    void retourneErreurProfilSiTokenInvalide() {
        when(tokenService.getUserFromToken("Bearer mauvais")).thenReturn(null);

        Map<String, Object> result = profileController.getProfile("Bearer mauvais");

        Assertions.assertTrue(result.containsKey("error"));
    }

    /**
     * Vérifie le contrat de format : la map retournée contient les 5 champs publics
     * du profil. Si on ajoute / retire un champ, ce test est le canari à mettre à jour.
     */
    @Test
    void profilContientTousLesChamps() {
        when(tokenService.getUserFromToken("Bearer token")).thenReturn(user);

        Map<String, Object> result = profileController.getProfile("Bearer token");

        Assertions.assertTrue(result.containsKey("id"));
        Assertions.assertTrue(result.containsKey("name"));
        Assertions.assertTrue(result.containsKey("email"));
        Assertions.assertTrue(result.containsKey("avatar"));
        Assertions.assertTrue(result.containsKey("createdAt"));
    }

    // ── uploadAvatar ──────────────────────────────────────────────────────────

    /**
     * Token absent → l'upload est rejeté avant même de lire le fichier.
     */
    @Test
    void retourneErreurAvatarSiTokenInvalide() {
        when(tokenService.getUserFromToken(null)).thenReturn(null);

        // Fichier valide mais sera ignoré car la garde token kick d'abord.
        MockMultipartFile file = new MockMultipartFile(
                "file", "photo.jpg", "image/jpeg", new byte[]{1, 2, 3});

        Map<String, Object> result = profileController.uploadAvatar(null, file);

        Assertions.assertTrue(result.containsKey("error"));
    }

    /**
     * Validation : fichier de 0 octets → erreur explicite "fichier vide".
     */
    @Test
    void retourneErreurSiFichierVide() {
        when(tokenService.getUserFromToken("Bearer token")).thenReturn(user);

        // byte[0] simule un upload où l'utilisateur a annulé en cours.
        MockMultipartFile file = new MockMultipartFile(
                "file", "photo.jpg", "image/jpeg", new byte[0]);

        Map<String, Object> result = profileController.uploadAvatar("Bearer token", file);

        Assertions.assertTrue(result.containsKey("error"));
        Assertions.assertTrue(result.get("error").toString().contains("vide"));
    }

    /**
     * Sécurité : on n'accepte QUE des images (jpeg, png, gif). Un PDF est rejeté
     * pour éviter qu'un attaquant uploade un fichier malveillant déguisé.
     */
    @Test
    void retourneErreurSiTypeNonImage() {
        when(tokenService.getUserFromToken("Bearer token")).thenReturn(user);

        // Type MIME application/pdf → le contrôleur doit rejeter.
        MockMultipartFile file = new MockMultipartFile(
                "file", "document.pdf", "application/pdf", new byte[]{1, 2, 3});

        Map<String, Object> result = profileController.uploadAvatar("Bearer token", file);

        Assertions.assertTrue(result.containsKey("error"));
        Assertions.assertTrue(result.get("error").toString().contains("images"));
    }

    /**
     * Cas nominal : JPEG valide → fichier sauvegardé, user mis à jour, URL retournée.
     */
    @Test
    void uploadeAvatarAvecSucces() {
        when(tokenService.getUserFromToken("Bearer token")).thenReturn(user);
        when(userRepository.save(any(User.class))).thenReturn(user);

        // 0xFFD8 = magic bytes JPEG (utile pour des validations futures).
        MockMultipartFile file = new MockMultipartFile(
                "file", "photo.jpg", "image/jpeg", new byte[]{(byte) 0xFF, (byte) 0xD8});

        Map<String, Object> result = profileController.uploadAvatar("Bearer token", file);

        Assertions.assertFalse(result.containsKey("error"), "Ne doit pas avoir d'erreur");
        Assertions.assertTrue(result.containsKey("avatarUrl"));
        String avatarUrl = (String) result.get("avatarUrl");
        // L'URL retournée préfixe le baseUrl injecté en setup.
        Assertions.assertTrue(avatarUrl.startsWith("http://localhost:8000"));
    }

    /**
     * Vérifie que l'extension du fichier d'origine est préservée dans l'URL générée
     * (.png reste .png, pas converti en .jpg arbitrairement).
     */
    @Test
    void avatarUrlContientExtensionFichier() {
        when(tokenService.getUserFromToken("Bearer token")).thenReturn(user);
        when(userRepository.save(any(User.class))).thenReturn(user);

        MockMultipartFile file = new MockMultipartFile(
                "file", "photo.png", "image/png", new byte[]{1, 2, 3});

        Map<String, Object> result = profileController.uploadAvatar("Bearer token", file);

        String avatarUrl = (String) result.get("avatarUrl");
        Assertions.assertTrue(avatarUrl.endsWith(".png"));
    }

    /**
     * Cas limite : fichier sans extension dans son nom → fallback sur .jpg
     * (le code prod choisit jpg comme extension par défaut pour avoir un fichier
     * exploitable côté navigateur).
     */
    @Test
    void avatarUrlUtiliseJpgParDefautSiPasExtension() {
        when(tokenService.getUserFromToken("Bearer token")).thenReturn(user);
        when(userRepository.save(any(User.class))).thenReturn(user);

        // Pas de "." dans le nom → pas d'extension extractible.
        MockMultipartFile file = new MockMultipartFile(
                "file", "photo", "image/jpeg", new byte[]{1, 2, 3});

        Map<String, Object> result = profileController.uploadAvatar("Bearer token", file);

        String avatarUrl = (String) result.get("avatarUrl");
        Assertions.assertTrue(avatarUrl.endsWith(".jpg"));
    }
}
