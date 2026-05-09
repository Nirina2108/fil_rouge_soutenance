package com.example.auth.config;

import jakarta.servlet.FilterChain;
import org.junit.jupiter.api.Test;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.mock.web.MockHttpServletResponse;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.times;
import static org.mockito.Mockito.verify;

/**
 * Tests unitaires du filtre CORS {@link CorsConfig}.
 *
 * Couverture :
 *   - origine autorisée : les en-têtes CORS sont posés et la requête passe au prochain filtre.
 *   - origine inconnue : Access-Control-Allow-Origin n'est PAS posé (sécurité).
 *   - méthode OPTIONS (préflight) : on répond 200 sans appeler la chaîne.
 *   - le bean Spring est correctement exposé.
 *
 * On utilise MockHttpServletRequest/Response (Spring) plutôt que de monter un
 * vrai serveur — beaucoup plus rapide et suffisant pour tester la logique de filtre.
 */
class CorsConfigTest {

    /**
     * Cas nominal : une requête GET avec Origin autorisée doit recevoir tous
     * les en-têtes CORS attendus, ET poursuivre dans la chaîne de filtres.
     */
    @Test
    void doFilter_setHeadersAndContinue_forAllowedOrigin() throws Exception {
        CorsConfig filter = new CorsConfig();
        MockHttpServletRequest request = new MockHttpServletRequest("GET", "/api/auth/me");
        // Origine du frontend SkillHub local — fait partie de la whitelist.
        request.addHeader("Origin", "http://localhost:5173");
        MockHttpServletResponse response = new MockHttpServletResponse();
        // Mock de la suite du pipeline pour vérifier qu'elle est bien appelée.
        FilterChain chain = mock(FilterChain.class);

        filter.doFilter(request, response, chain);

        // Vérifie que tous les en-têtes CORS sont posés sur la réponse.
        assertEquals("http://localhost:5173", response.getHeader("Access-Control-Allow-Origin"));
        assertEquals("GET, POST, PUT, DELETE, OPTIONS", response.getHeader("Access-Control-Allow-Methods"));
        assertEquals("Authorization, Content-Type, Accept", response.getHeader("Access-Control-Allow-Headers"));
        assertEquals("true", response.getHeader("Access-Control-Allow-Credentials"));
        assertEquals("3600", response.getHeader("Access-Control-Max-Age"));
        // Vérifie que la requête est passée au prochain filtre (1 seule fois).
        verify(chain, times(1)).doFilter(request, response);
    }

    /**
     * Cas sécurité : une requête depuis une origine NON whitelistée ne doit pas
     * recevoir l'en-tête Allow-Origin (sinon le navigateur la laisserait passer).
     * Le filtre laisse quand même passer la requête (pour une éventuelle
     * autre couche de sécurité côté contrôleur).
     */
    @Test
    void doFilter_doesNotSetAllowOrigin_forUnknownOrigin() throws Exception {
        CorsConfig filter = new CorsConfig();
        MockHttpServletRequest request = new MockHttpServletRequest("GET", "/api/auth/me");
        // Origine NON whitelistée — simulera une attaque CSRF cross-domain.
        request.addHeader("Origin", "https://evil.example");
        MockHttpServletResponse response = new MockHttpServletResponse();
        FilterChain chain = mock(FilterChain.class);

        filter.doFilter(request, response, chain);

        // L'en-tête Allow-Origin doit être absent → le navigateur bloquera la réponse.
        assertNull(response.getHeader("Access-Control-Allow-Origin"));
        verify(chain, times(1)).doFilter(request, response);
    }

    /**
     * Préflight CORS : une requête OPTIONS doit être court-circuitée (status 200)
     * sans atteindre le contrôleur. Vérifie que la chaîne n'est PAS appelée.
     */
    @Test
    void doFilter_returns200AndSkipsChain_forOptions() throws Exception {
        CorsConfig filter = new CorsConfig();
        // Méthode OPTIONS = preflight CORS standard.
        MockHttpServletRequest request = new MockHttpServletRequest("OPTIONS", "/api/auth/me");
        request.addHeader("Origin", "http://127.0.0.1:3000");
        MockHttpServletResponse response = new MockHttpServletResponse();
        FilterChain chain = mock(FilterChain.class);

        filter.doFilter(request, response, chain);

        // Code 200 + en-têtes CORS sur la réponse...
        assertEquals(200, response.getStatus());
        assertEquals("http://127.0.0.1:3000", response.getHeader("Access-Control-Allow-Origin"));
        // ...mais le contrôleur n'a PAS été atteint (times(0)).
        verify(chain, times(0)).doFilter(request, response);
    }

    /**
     * Vérifie que la méthode @Bean corsFilter() retourne bien une instance non null.
     * Trivial mais nécessaire pour la couverture (et garantit que le bean est registrable).
     */
    @Test
    void corsFilterBean_returnsInstance() {
        CorsConfig filter = new CorsConfig();
        CorsConfig bean = filter.corsFilter();

        assertNotNull(bean);
    }
}
