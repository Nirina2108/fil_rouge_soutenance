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

import java.time.LocalDateTime;
import java.util.Arrays;
import java.util.Collections;
import java.util.List;
import java.util.Map;

import static org.mockito.Mockito.when;

/**
 * Tests unitaires du {@link UserController}.
 *
 * Vérifie l'endpoint GET /api/users qui retourne la liste de tous les
 * utilisateurs SAUF le demandeur lui-même. La sécurité repose sur le
 * TokenService : si le token Bearer est invalide ou absent, une map d'erreur
 * est retournée à la place de la liste.
 *
 * Pas de @SpringBootTest : on mocke UserRepository et TokenService avec Mockito,
 * c'est suffisant pour tester la logique du contrôleur (plus rapide qu'un test
 * Web complet).
 */
@ExtendWith(MockitoExtension.class)
class UserControllerTest {

    /** Mock du repository utilisateur (findAll retourne ce qu'on veut). */
    @Mock
    private UserRepository userRepository;

    /** Mock du service de token : on simule la validation du Bearer. */
    @Mock
    private TokenService tokenService;

    /** SUT — Mockito injecte automatiquement les mocks dans le constructeur. */
    @InjectMocks
    private UserController userController;

    /** Utilisateur "demandeur" (celui qui appelle l'API avec son token). */
    private User requester;

    /** Autre utilisateur en base — celui qu'on s'attend à voir retourné. */
    private User otherUser;

    /**
     * Prépare deux utilisateurs en mémoire avant chaque test.
     * Pas de DB — on alimente directement les mocks dans chaque test.
     */
    @BeforeEach
    void setUp() {
        requester = new User();
        requester.setId(1L);
        requester.setName("Alice");
        requester.setEmail("alice@test.com");
        requester.setToken("token-alice");
        // Token valide pour 1h.
        requester.setTokenExpiresAt(LocalDateTime.now().plusHours(1));

        otherUser = new User();
        otherUser.setId(2L);
        otherUser.setName("Bob");
        otherUser.setEmail("bob@test.com");
    }

    // ── getUsers — token invalide ─────────────────────────────────────────────

    /**
     * Pas de header Authorization → token null → tokenService renvoie null →
     * le contrôleur doit renvoyer une map avec la clé "error".
     */
    @Test
    void retourneErreurSiTokenNull() {
        when(tokenService.getUserFromToken(null)).thenReturn(null);

        Object result = userController.getUsers(null);

        // Le retour est une Map (pas une List) → le frontend détecte l'erreur via la clé "error".
        Assertions.assertInstanceOf(Map.class, result);
        @SuppressWarnings("unchecked")
        Map<String, Object> map = (Map<String, Object>) result;
        Assertions.assertTrue(map.containsKey("error"));
    }

    /**
     * Token Bearer présent mais invalide (expiré, signature incorrecte, etc.) →
     * même comportement que pour un token absent.
     */
    @Test
    void retourneErreurSiTokenInvalide() {
        when(tokenService.getUserFromToken("Bearer invalide")).thenReturn(null);

        Object result = userController.getUsers("Bearer invalide");

        Assertions.assertInstanceOf(Map.class, result);
        @SuppressWarnings("unchecked")
        Map<String, Object> map = (Map<String, Object>) result;
        Assertions.assertTrue(map.containsKey("error"));
    }

    // ── getUsers — token valide ───────────────────────────────────────────────

    /**
     * Token valide → la liste est retournée. Avec 2 users en base (dont le demandeur),
     * la liste résultante doit contenir UN seul utilisateur (l'autre).
     */
    @Test
    void retourneListeUtilisateursAvecTokenValide() {
        when(tokenService.getUserFromToken("Bearer token-alice")).thenReturn(requester);
        when(userRepository.findAll()).thenReturn(Arrays.asList(requester, otherUser));

        Object result = userController.getUsers("Bearer token-alice");

        Assertions.assertInstanceOf(List.class, result);
        @SuppressWarnings("unchecked")
        List<Map<String, Object>> list = (List<Map<String, Object>>) result;
        // Demandeur exclu → 2 - 1 = 1 entrée attendue.
        Assertions.assertEquals(1, list.size());
    }

    /**
     * Cœur de la règle métier : le demandeur ne doit JAMAIS apparaître dans
     * sa propre liste de contacts (il n'a pas besoin de se voir).
     */
    @Test
    void exclutLeDemandeurDeLaListe() {
        when(tokenService.getUserFromToken("Bearer token-alice")).thenReturn(requester);
        when(userRepository.findAll()).thenReturn(Arrays.asList(requester, otherUser));

        @SuppressWarnings("unchecked")
        List<Map<String, Object>> list =
                (List<Map<String, Object>>) userController.getUsers("Bearer token-alice");

        // Vérifie via stream qu'aucune entrée n'a l'id du demandeur (1).
        boolean containsRequester = list.stream()
                .anyMatch(m -> m.get("id").equals(1L));
        Assertions.assertFalse(containsRequester);
    }

    /**
     * Symétrique du précédent : les autres utilisateurs DOIVENT apparaître,
     * et leurs champs name/email doivent être correctement extraits.
     */
    @Test
    void inclutAutresUtilisateurs() {
        when(tokenService.getUserFromToken("Bearer token-alice")).thenReturn(requester);
        when(userRepository.findAll()).thenReturn(Arrays.asList(requester, otherUser));

        @SuppressWarnings("unchecked")
        List<Map<String, Object>> list =
                (List<Map<String, Object>>) userController.getUsers("Bearer token-alice");

        // Bob est l'autre utilisateur → vérifie que ses champs sont préservés.
        Assertions.assertEquals("Bob", list.get(0).get("name"));
        Assertions.assertEquals("bob@test.com", list.get(0).get("email"));
    }

    /**
     * Cas limite : un seul utilisateur en base (le demandeur lui-même) →
     * la liste retournée doit être vide (pas null, pas d'erreur).
     */
    @Test
    void retourneListeVideSiSeulUtilisateur() {
        when(tokenService.getUserFromToken("Bearer token-alice")).thenReturn(requester);
        when(userRepository.findAll()).thenReturn(Collections.singletonList(requester));

        @SuppressWarnings("unchecked")
        List<Map<String, Object>> list =
                (List<Map<String, Object>>) userController.getUsers("Bearer token-alice");

        Assertions.assertTrue(list.isEmpty());
    }

    /**
     * Vérifie le contrat de format : chaque entrée de la liste contient
     * exactement les clés id, name, email (et seulement celles-ci).
     */
    @Test
    void chaqueEntreeContientIdNameEmail() {
        when(tokenService.getUserFromToken("Bearer token-alice")).thenReturn(requester);
        when(userRepository.findAll()).thenReturn(Arrays.asList(requester, otherUser));

        @SuppressWarnings("unchecked")
        List<Map<String, Object>> list =
                (List<Map<String, Object>>) userController.getUsers("Bearer token-alice");

        Map<String, Object> entry = list.get(0);
        // Les 3 champs sont le contrat documenté avec le frontend (UserController.java).
        Assertions.assertTrue(entry.containsKey("id"));
        Assertions.assertTrue(entry.containsKey("name"));
        Assertions.assertTrue(entry.containsKey("email"));
    }
}
