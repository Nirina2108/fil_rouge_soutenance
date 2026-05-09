package com.example.auth.service;

import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.ArgumentCaptor;
import org.mockito.InjectMocks;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;
import org.springframework.mail.SimpleMailMessage;
import org.springframework.mail.javamail.JavaMailSender;
import org.springframework.test.util.ReflectionTestUtils;

import static org.mockito.Mockito.*;

/**
 * Tests unitaires du {@link EmailService}.
 *
 * Couvre les 3 cas du service d'envoi d'emails de vérification :
 *   1. Cas nominal : mailSender disponible → envoi réussi.
 *   2. Mode dev sans SMTP : mailSender null → no-op silencieux (pas de NPE).
 *   3. Échec SMTP : mailSender lève une exception → on l'avale silencieusement
 *      pour ne pas faire échouer l'inscription si le serveur mail est HS.
 *
 * On utilise ArgumentCaptor pour intercepter les SimpleMailMessage envoyés
 * et inspecter leurs propriétés (to, subject, text).
 */
@ExtendWith(MockitoExtension.class)
class EmailServiceTest {

    /** Mock du sender Spring Mail — on ne fait pas vraiment d'envoi SMTP. */
    @Mock
    private JavaMailSender mailSender;

    /** SUT — Mockito injecte le mailSender dans le constructeur. */
    @InjectMocks
    private EmailService emailService;

    /**
     * Avant chaque test : injecte la valeur baseUrl (résolue normalement par @Value).
     */
    @BeforeEach
    void setUp() {
        ReflectionTestUtils.setField(emailService, "baseUrl", "http://localhost:8000");
    }

    // ── sendVerificationEmail avec mailSender disponible ─────────────────────

    /**
     * Cas nominal : mailSender mock → on capture l'argument pour vérifier le destinataire.
     */
    @Test
    void envoieEmailQuandMailSenderPresent() {
        emailService.sendVerificationEmail("user@test.com", "token-abc");

        // ArgumentCaptor : intercepte le SimpleMailMessage envoyé pour assertions.
        ArgumentCaptor<SimpleMailMessage> captor = ArgumentCaptor.forClass(SimpleMailMessage.class);
        verify(mailSender, times(1)).send(captor.capture());

        SimpleMailMessage sent = captor.getValue();
        Assertions.assertNotNull(sent.getTo());
        // Vérifie que le destinataire est bien l'email passé en paramètre.
        Assertions.assertEquals("user@test.com", sent.getTo()[0]);
    }

    /**
     * Le corps de l'email doit contenir le lien de vérification = baseUrl + token.
     * Sans cela, l'utilisateur ne peut pas activer son compte.
     */
    @Test
    void emailContientLienDeVerification() {
        emailService.sendVerificationEmail("user@test.com", "mon-token");

        ArgumentCaptor<SimpleMailMessage> captor = ArgumentCaptor.forClass(SimpleMailMessage.class);
        verify(mailSender).send(captor.capture());

        String text = captor.getValue().getText();
        Assertions.assertNotNull(text);
        // Le token doit apparaître tel quel dans le lien (c'est ce sur quoi
        // l'utilisateur clique pour confirmer son compte).
        Assertions.assertTrue(text.contains("mon-token"));
        Assertions.assertTrue(text.contains("http://localhost:8000"));
    }

    /**
     * Vérifie qu'un sujet est défini (sinon l'email risque d'aller en spam).
     */
    @Test
    void emailContientSujetAttendu() {
        emailService.sendVerificationEmail("user@test.com", "tok");

        ArgumentCaptor<SimpleMailMessage> captor = ArgumentCaptor.forClass(SimpleMailMessage.class);
        verify(mailSender).send(captor.capture());

        Assertions.assertNotNull(captor.getValue().getSubject());
    }

    // ── sendVerificationEmail sans mailSender (mode dev) ─────────────────────

    /**
     * Mode dev local sans SMTP configuré : mailSender peut être null.
     * Le service doit gérer ce cas sans NullPointerException pour ne pas
     * faire échouer l'inscription en local.
     */
    @Test
    void nEnvoisPasEmailSiMailSenderNull() {
        // On instancie un service "vierge" SANS injecter mailSender.
        EmailService devService = new EmailService();
        ReflectionTestUtils.setField(devService, "baseUrl", "http://localhost:8000");
        // mailSender reste null (non injecté).

        // assertDoesNotThrow : succès si AUCUNE exception n'est levée.
        Assertions.assertDoesNotThrow(
                () -> devService.sendVerificationEmail("dev@test.com", "token-dev")
        );
    }

    // ── sendVerificationEmail — exception silencieuse ─────────────────────────

    /**
     * Si SMTP plante (panne, mauvaise config), le service avale l'exception
     * pour ne pas casser l'inscription. L'utilisateur peut toujours se connecter,
     * mais ne reçoit pas l'email — un mécanisme de "renvoyer le mail" est prévu côté UI.
     */
    @Test
    void ignoreExceptionLorsEnvoiEchoue() {
        // Simule une panne SMTP : send() lève RuntimeException.
        doThrow(new RuntimeException("SMTP error")).when(mailSender).send(any(SimpleMailMessage.class));

        // L'appel ne doit PAS propager l'exception (sinon l'inscription échouerait).
        Assertions.assertDoesNotThrow(
                () -> emailService.sendVerificationEmail("user@test.com", "token-fail")
        );
    }
}
