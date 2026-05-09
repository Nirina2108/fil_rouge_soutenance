package com.example.auth.service;

import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.io.File;
import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.util.List;

/**
 * Tests unitaires du {@link TransactionLogService}.
 *
 * Vérifie l'écriture du journal d'audit dans logs/transactions.log :
 *   - création automatique du dossier et fichier au premier append.
 *   - format de chaque ligne : [timestamp] [action] [email] [statut] detail.
 *   - appel multiple = append (pas de remplacement).
 *
 * Tests d'intégration léger : on écrit dans un VRAI fichier (pas de mock du
 * filesystem) car la logique de I/O est trop centrale pour être mockée.
 * Le tearDown nettoie le fichier pour ne pas polluer le repo.
 */
class TransactionLogServiceTest {

    /** SUT — instancié à neuf avant chaque test. */
    private TransactionLogService logService;

    /** Chemin du fichier de log utilisé par le service. */
    private static final Path LOG_PATH = Paths.get("logs/transactions.log");

    /**
     * Avant chaque test : nouveau service + suppression du log précédent
     * pour repartir d'un état propre (sinon les comptages seraient faussés).
     */
    @BeforeEach
    void setUp() throws IOException {
        logService = new TransactionLogService();
        // Supprimer le fichier de log s'il existe déjà.
        Files.deleteIfExists(LOG_PATH);
    }

    /**
     * Après chaque test : on supprime le fichier pour ne pas le commiter
     * (le dossier logs/ est dans .gitignore mais sécurité supplémentaire).
     */
    @AfterEach
    void tearDown() throws IOException {
        Files.deleteIfExists(LOG_PATH);
    }

    // ── log ───────────────────────────────────────────────────────────────────

    /**
     * Un appel à log() crée le fichier et écrit exactement 1 ligne.
     */
    @Test
    void ecritUneLigneDansLeFichier() throws IOException {
        logService.log("REGISTER", "user@test.com", "SUCCESS", "test detail");

        Assertions.assertTrue(LOG_PATH.toFile().exists(), "Le fichier de log doit être créé");

        List<String> lines = Files.readAllLines(LOG_PATH);
        Assertions.assertEquals(1, lines.size());
    }

    /**
     * La ligne doit contenir les 3 champs principaux : action, email, statut.
     */
    @Test
    void ligneContientActionEmailStatut() throws IOException {
        logService.log("LOGIN", "alice@test.com", "SUCCESS", "detail");

        List<String> lines = Files.readAllLines(LOG_PATH);
        String line = lines.get(0);

        Assertions.assertTrue(line.contains("LOGIN"));
        Assertions.assertTrue(line.contains("alice@test.com"));
        Assertions.assertTrue(line.contains("SUCCESS"));
    }

    /**
     * Le 4e paramètre "detail" doit aussi être présent dans la ligne (pour les debug).
     */
    @Test
    void ligneContientLeDetail() throws IOException {
        logService.log("LOGOUT", "bob@test.com", "SUCCESS", "detail-specifique");

        List<String> lines = Files.readAllLines(LOG_PATH);
        Assertions.assertTrue(lines.get(0).contains("detail-specifique"));
    }

    /**
     * Vérifie le format du timestamp en début de ligne (regex strict).
     * Si on change le format, ce test casse — c'est voulu : il sert de canari
     * pour les autres systèmes qui parsent ces logs.
     */
    @Test
    void ligneContientTimestamp() throws IOException {
        logService.log("REGISTER", "user@test.com", "SUCCESS", "");

        List<String> lines = Files.readAllLines(LOG_PATH);
        // Le timestamp a le format [yyyy-MM-dd HH:mm:ss].
        Assertions.assertTrue(lines.get(0).matches("\\[\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}\\].*"));
    }

    /**
     * Appels multiples → append (3 appels = 3 lignes), pas de truncate du fichier.
     */
    @Test
    void appendeMultipleAppels() throws IOException {
        logService.log("REGISTER", "user1@test.com", "SUCCESS", "");
        logService.log("LOGIN", "user2@test.com", "FAILURE", "");
        logService.log("LOGOUT", "user3@test.com", "SUCCESS", "");

        List<String> lines = Files.readAllLines(LOG_PATH);
        Assertions.assertEquals(3, lines.size());
    }

    /**
     * Si le dossier logs/ n'existe pas, le service doit le créer automatiquement
     * (sinon le premier appel planterait sur un FileNotFoundException).
     */
    @Test
    void creedossierLogsAutomatiquement() throws IOException {
        // On supprime le dossier s'il est vide (préparation du cas où logs/ n'existe pas).
        File logsDir = new File("logs");
        if (logsDir.exists() && logsDir.isDirectory() && logsDir.listFiles() != null
                && logsDir.listFiles().length == 0) {
            logsDir.delete();
        }

        logService.log("TEST", "user@test.com", "SUCCESS", "");

        // Le dossier doit avoir été créé par le service.
        Assertions.assertTrue(logsDir.exists());
    }

    /**
     * Vérifie que le formatage utilise bien des crochets pour chaque champ
     * (parser-friendly pour des outils type ELK ou grep).
     */
    @Test
    void formatContientCrochets() throws IOException {
        logService.log("VERIFY", "user@test.com", "SUCCESS", "detail");

        List<String> lines = Files.readAllLines(LOG_PATH);
        String line = lines.get(0);
        // Format attendu : [timestamp] [action] [email] [statut] detail.
        Assertions.assertTrue(line.contains("[VERIFY]"));
        Assertions.assertTrue(line.contains("[user@test.com]"));
        Assertions.assertTrue(line.contains("[SUCCESS]"));
    }
}
