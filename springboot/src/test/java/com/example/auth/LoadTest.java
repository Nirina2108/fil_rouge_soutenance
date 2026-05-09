package com.example.auth;

import com.example.auth.entity.User;
import com.example.auth.repository.UserRepository;
import com.example.auth.service.PasswordCryptoService;
import org.junit.jupiter.api.Tag;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.test.context.ActiveProfiles;

import java.time.LocalDateTime;
import java.util.ArrayList;
import java.util.List;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;

/**
 * Test de charge / volume du service d'authentification Spring Boot.
 *
 * Vérifie que la persistance JPA tient sur un grand nombre d'utilisateurs.
 * Volume paramétrable via la propriété système {@code load.test.volume}
 * (défaut 1000). Exemple :
 * <pre>
 *   ./mvnw test -Dload.test.volume=10000 -Dgroups=heavy
 * </pre>
 *
 * Ce test est marqué {@code @Tag("heavy")} : exclu par défaut du build Maven
 * via la configuration de surefire dans pom.xml. À lancer explicitement.
 *
 * NB : pas de "formations" côté Spring Boot — cette entité n'existe que dans
 * le backend Laravel SkillHub. Le service d'auth ne gère que des Users et
 * des nonces. Donc ce LoadTest ne couvre que le volet utilisateurs.
 */
@SpringBootTest(classes = AuthApplication.class)
@ActiveProfiles("test")
@Tag("heavy")
class LoadTest {

    /** Repository utilisateur — utilisé pour le bulk save et les vérifications. */
    @Autowired
    private UserRepository userRepository;

    /** Service de chiffrement réversible AES/GCM (pour générer le password_encrypted). */
    @Autowired
    private PasswordCryptoService passwordCryptoService;

    /**
     * Lit le volume cible depuis -Dload.test.volume=N (défaut 1000).
     * On se contente de System.getProperty (les tests Spring Boot peuvent aussi
     * lire des @Value, mais c'est plus simple ici de garder la logique en pur Java).
     */
    private int volume() {
        return Math.max(1, Integer.parseInt(System.getProperty("load.test.volume", "1000")));
    }

    /**
     * Bulk insert de N utilisateurs : créés via JPA saveAll (insert batch
     * géré par Hibernate). Vérifie ensuite que tous sont retrouvables en base.
     */
    @Test
    void inscription_de_N_utilisateurs_persiste_en_base() {
        int n = volume();
        // Reset pour partir d'une base propre — pas de @Transactional sinon le saveAll
        // serait roll-backé et les vérifications mesureraient un état incorrect.
        userRepository.deleteAll();

        // Chiffre le mot de passe une seule fois et réutilise (gain ~10x sur 10000 users).
        String passwordEncrypted = passwordCryptoService.encrypt("password123");
        LocalDateTime now = LocalDateTime.now();

        // Construction de la liste en mémoire — JPA saveAll gère le batching.
        List<User> users = new ArrayList<>(n);
        for (int i = 0; i < n; i++) {
            User u = new User();
            u.setName("Apprenant" + i);
            // Email unique pour passer la contrainte UNIQUE.
            u.setEmail("apprenant_load_" + i + "@test.com");
            u.setRole("apprenant");
            u.setPasswordEncrypted(passwordEncrypted);
            u.setEmailVerified(true);
            u.setCreatedAt(now);
            users.add(u);
        }

        long debut = System.currentTimeMillis();
        // saveAll : Hibernate flush par batch (cf. spring.jpa.properties.hibernate.jdbc.batch_size).
        userRepository.saveAll(users);
        long duree = System.currentTimeMillis() - debut;

        System.out.println("\n[LoadTest] " + n + " users persistes en " + duree + "ms");

        // Vérifie le count global.
        assertEquals(n, userRepository.count());

        // Vérifie qu'un user random est bien retrouvable par email (pas juste un count en bloc).
        int sampleIndex = n / 2;
        User retrieved = userRepository.findByEmail("apprenant_load_" + sampleIndex + "@test.com")
                .orElse(null);
        assertNotNull(retrieved);
        assertEquals("Apprenant" + sampleIndex, retrieved.getName());
    }

    /**
     * Variant : connexion par échantillonnage après bulk insert.
     *
     * Insère N users puis vérifie qu'on peut récupérer 5 d'entre eux par email
     * (équivalent du flow de login : findByEmail + vérification user existant).
     * On ne fait pas l'authentification HMAC complète (trop lourde sur 5 cas)
     * — le but est de mesurer la vitesse de findByEmail sur un dataset chargé.
     */
    @Test
    void recuperation_par_email_echantillon_apres_N_inscriptions() {
        int n = volume();
        userRepository.deleteAll();

        String passwordEncrypted = passwordCryptoService.encrypt("password123");
        LocalDateTime now = LocalDateTime.now();

        List<User> users = new ArrayList<>(n);
        for (int i = 0; i < n; i++) {
            User u = new User();
            u.setName("User" + i);
            u.setEmail("user_load_" + i + "@test.com");
            u.setRole("apprenant");
            u.setPasswordEncrypted(passwordEncrypted);
            u.setEmailVerified(true);
            u.setCreatedAt(now);
            users.add(u);
        }
        userRepository.saveAll(users);

        // 5 indices répartis dans le dataset (début / quart / milieu / 3-quarts / fin).
        int[] indices = { 0, n / 4, n / 2, 3 * n / 4, n - 1 };

        long debut = System.currentTimeMillis();
        for (int i : indices) {
            User u = userRepository.findByEmail("user_load_" + i + "@test.com")
                    .orElseThrow(() -> new AssertionError("User " + i + " introuvable"));
            assertEquals("User" + i, u.getName());
        }
        long duree = System.currentTimeMillis() - debut;

        System.out.println("\n[LoadTest] 5 findByEmail echantillonnes parmi " + n + " en " + duree + "ms");
    }
}
