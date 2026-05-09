package com.example.auth.service;

import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.Test;

import java.lang.reflect.Method;

/**
 * Tests de la méthode privée {@code AuthService.constantTimeEquals}.
 *
 * IMPORTANT pour la sécurité : la comparaison de hashes/preuves doit se faire
 * en temps CONSTANT pour ne pas leaker la longueur du préfixe correct via une
 * attaque par timing (ex. une comparaison de String par défaut s'arrête au
 * premier byte différent → un attaquant peut deviner caractère par caractère).
 *
 * On utilise la réflexion pour accéder à la méthode privée — pas besoin de
 * l'exposer en public juste pour les tests, ce qui réduit la surface API.
 *
 * @author Nirina
 * @version 1.0
 */
public class ConstantTimeEqualsTest {

    /**
     * Cas nominal : deux chaînes identiques → true.
     */
    @Test
    void testConstantTimeEqualsTrue() throws Exception {
        // On crée un AuthService avec toutes les dépendances null — on n'utilise
        // pas la logique métier, juste la méthode privée constantTimeEquals.
        AuthService service = new AuthService(null, null, null, null, null, null, null, null);

        // Récupère la méthode privée par réflexion + désactive le check d'accès.
        Method method = AuthService.class.getDeclaredMethod("constantTimeEquals", String.class, String.class);
        method.setAccessible(true);

        boolean result = (boolean) method.invoke(service, "abc123", "abc123");

        Assertions.assertTrue(result);
    }

    /**
     * Deux chaînes même longueur, un caractère diffère → false.
     * Le timing attendu doit être identique au cas "égalité parfaite".
     */
    @Test
    void testConstantTimeEqualsFalse() throws Exception {
        AuthService service = new AuthService(null, null, null, null, null, null, null, null);

        Method method = AuthService.class.getDeclaredMethod("constantTimeEquals", String.class, String.class);
        method.setAccessible(true);

        // Diffère uniquement sur le dernier caractère (3 vs 4).
        boolean result = (boolean) method.invoke(service, "abc123", "abc124");

        Assertions.assertFalse(result);
    }

    /**
     * Cas particulier : longueurs différentes → false immédiat.
     * À noter : ce cas seul n'est PAS en temps constant — mais il est OK
     * d'exposer la longueur publique (le hash a une taille connue de l'attaquant).
     */
    @Test
    void testConstantTimeEqualsDifferentLength() throws Exception {
        AuthService service = new AuthService(null, null, null, null, null, null, null, null);

        Method method = AuthService.class.getDeclaredMethod("constantTimeEquals", String.class, String.class);
        method.setAccessible(true);

        boolean result = (boolean) method.invoke(service, "abc", "abcdef");

        Assertions.assertFalse(result);
    }
}