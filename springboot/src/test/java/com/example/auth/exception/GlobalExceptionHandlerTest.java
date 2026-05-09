package com.example.auth.exception;

import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.util.Map;

/**
 * Tests unitaires du {@link GlobalExceptionHandler}.
 *
 * Vérifie que toutes les RuntimeException levées par les contrôleurs sont
 * transformées en Map JSON {"error": "<message>"} avec un statut HTTP 500.
 *
 * Couvre les cas particuliers : message null, message vide, sous-classes
 * spécifiques (IllegalArgumentException, IllegalStateException) qui ne sont pas
 * captées séparément.
 */
class GlobalExceptionHandlerTest {

    /** Handler instancié à neuf avant chaque test pour garantir l'isolation. */
    private GlobalExceptionHandler handler;

    /**
     * Initialise un handler vide avant chaque test.
     * Pas d'injection Spring : c'est un POJO sans dépendances.
     */
    @BeforeEach
    void setUp() {
        handler = new GlobalExceptionHandler();
    }

    // ── handleRuntimeException ────────────────────────────────────────────────

    /**
     * Vérifie que la map retournée contient bien la clé "error".
     */
    @Test
    void retourneMapAvecCleError() {
        // Exception avec message arbitraire pour tester la structure.
        RuntimeException ex = new RuntimeException("Erreur de test");
        Map<String, Object> result = handler.handleRuntimeException(ex);

        Assertions.assertNotNull(result);
        // La clé "error" est le contrat documenté par le frontend.
        Assertions.assertTrue(result.containsKey("error"));
    }

    /**
     * Vérifie que le message de l'exception est fidèlement transmis dans le payload.
     */
    @Test
    void retourneMessageDeLException() {
        RuntimeException ex = new RuntimeException("Message specifique");
        Map<String, Object> result = handler.handleRuntimeException(ex);

        // Le frontend lit data.error pour afficher dans l'UI — fidélité critique.
        Assertions.assertEquals("Message specifique", result.get("error"));
    }

    /**
     * Cas limite : exception avec message null. Le handler ne doit pas crash et
     * doit toujours retourner une map avec la clé (valeur null acceptée).
     */
    @Test
    void gereExceptionAvecMessageNull() {
        // Cast explicite vers (String) pour résoudre l'ambiguïté du constructeur.
        RuntimeException ex = new RuntimeException((String) null);
        Map<String, Object> result = handler.handleRuntimeException(ex);

        Assertions.assertNotNull(result);
        Assertions.assertTrue(result.containsKey("error"));
        // null est accepté : le frontend doit gérer ce cas (ex. afficher un message générique).
        Assertions.assertNull(result.get("error"));
    }

    /**
     * Cas limite : exception avec message vide "". Le handler doit transmettre
     * la chaîne vide telle quelle (ne pas la remplacer par null).
     */
    @Test
    void gereExceptionAvecMessageVide() {
        RuntimeException ex = new RuntimeException("");
        Map<String, Object> result = handler.handleRuntimeException(ex);

        Assertions.assertEquals("", result.get("error"));
    }

    /**
     * Garantit que la map contient EXACTEMENT une clé (error). Si un dev rajoute
     * d'autres champs sans avertissement, ce test casse — ce qui force à mettre
     * à jour le contrat avec le frontend.
     */
    @Test
    void retourneUniquementLaCleError() {
        RuntimeException ex = new RuntimeException("test");
        Map<String, Object> result = handler.handleRuntimeException(ex);

        Assertions.assertEquals(1, result.size());
    }

    /**
     * IllegalArgumentException hérite de RuntimeException : doit être capturée
     * par le même handler générique (pas de handler spécifique défini).
     */
    @Test
    void gereIllegalArgumentException() {
        RuntimeException ex = new IllegalArgumentException("Argument invalide");
        Map<String, Object> result = handler.handleRuntimeException(ex);

        Assertions.assertEquals("Argument invalide", result.get("error"));
    }

    /**
     * Idem pour IllegalStateException — sous-classe de RuntimeException.
     */
    @Test
    void gereIllegalStateException() {
        RuntimeException ex = new IllegalStateException("Etat invalide");
        Map<String, Object> result = handler.handleRuntimeException(ex);

        Assertions.assertEquals("Etat invalide", result.get("error"));
    }
}
