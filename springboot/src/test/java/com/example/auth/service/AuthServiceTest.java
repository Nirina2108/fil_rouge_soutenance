package com.example.auth.service;

import com.example.auth.AuthApplication;
import com.example.auth.dto.ChangePasswordRequest;
import com.example.auth.dto.ClientProofRequest;
import com.example.auth.dto.ClientProofResponse;
import com.example.auth.dto.LoginRequest;
import com.example.auth.dto.RegisterRequest;
import com.example.auth.entity.User;
import com.example.auth.repository.AuthNonceRepository;
import com.example.auth.repository.UserRepository;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.http.MediaType;
import org.springframework.test.context.ActiveProfiles;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.test.web.servlet.MvcResult;

import java.time.LocalDateTime;
import java.util.Map;

/**
 * Tests complets du service d'authentification Moustass CloudSec / SkillHub.
 *
 * Test d'intégration {@code @SpringBootTest} : boot complet du contexte Spring
 * avec le profil "test" activé (DB H2 en mémoire, mail désactivé).
 * Les services réels sont injectés via @Autowired — on teste donc la chaîne
 * complète (controller → service → repository → DB) sans mocks profonds.
 *
 * Sections couvertes (matérialisées par des séparateurs `// ─── … ───` dans le code) :
 *   1. Register : inscription, doublon email, validation password, envoi email.
 *   2. Verify Email : flux d'activation par token UUID.
 *   3. Login : flux complet HMAC challenge-response (génération nonce, calcul preuve,
 *      vérification serveur, émission JWT).
 *   4. /me : récupération du profil via Authorization Bearer.
 *   5. Logout : invalidation du token côté serveur.
 *   6. ChangePassword : modification du mot de passe (auth requise).
 *   7. Branches de validation manquantes : cas de passwords faibles, formats, etc.
 *   8. AuthController Integration : tests HTTP via MockMvc qui couvrent la sérialisation JSON.
 *
 * Avant chaque test, on nettoie auth_nonce + users en base pour partir d'un état propre
 * (sans @Transactional qui aurait masqué les vrais comportements de commit).
 *
 * @author Nirina
 * @version 1.0
 */
@SpringBootTest(classes = AuthApplication.class)
@ActiveProfiles("test")
public class AuthServiceTest {

    /** Service métier sous test (boot Spring complet). */
    @Autowired
    private AuthService authService;

    /** Service utilitaire pour calculer la preuve client (utilisé pour préparer les login). */
    @Autowired
    private ClientProofService clientProofService;

    /** Repository utilisateur (utilisé pour vérifier l'état en base après les opérations). */
    @Autowired
    private UserRepository userRepository;

    /** Repository des nonces serveur (utilisé pour cleanup et vérifications de TTL). */
    @Autowired
    private AuthNonceRepository authNonceRepository;

    /** Service de chiffrement réversible des mots de passe (test de la clé maître AES). */
    @Autowired
    private PasswordCryptoService passwordCryptoService;

    /** Service de génération/validation JWT — utilisé pour créer des tokens de test. */
    @Autowired
    private JwtService jwtService;

    /**
     * Reset complet de la DB H2 entre chaque test.
     * On purge nonces ET users (FK cascade non utilisée ici car la DB est in-memory).
     */
    @BeforeEach
    void setUp() {
        authNonceRepository.deleteAll();
        userRepository.deleteAll();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Inscrit un utilisateur et active son email directement en base.
     */
    private void registerDefaultUser() {
        RegisterRequest request = new RegisterRequest();
        request.setName("Poun");
        request.setEmail("poun@gmail.com");
        request.setPassword("Azerty1234!@");
        request.setRole("apprenant");
        authService.register(request);

        // Active l email directement en base pour les tests
        User user = userRepository.findByEmail("poun@gmail.com").orElseThrow();
        user.setEmailVerified(true);
        user.setEmailVerificationToken(null);
        userRepository.save(user);
    }

    private ClientProofResponse buildValidProof() {
        ClientProofRequest request = new ClientProofRequest();
        request.setEmail("poun@gmail.com");
        request.setPassword("Azerty1234!@");
        return clientProofService.buildProof(request);
    }

    private LoginRequest toLoginRequest(ClientProofResponse proof) {
        LoginRequest loginRequest = new LoginRequest();
        loginRequest.setEmail(proof.getEmail());
        loginRequest.setNonce(proof.getNonce());
        loginRequest.setTimestamp(proof.getTimestamp());
        loginRequest.setHmac(proof.getHmac());
        return loginRequest;
    }

    /**
     * Cree un utilisateur avec email verifie et un vrai JWT valide.
     */
    private String registerAndLogin(String email, String password) {
        RegisterRequest reg = new RegisterRequest();
        reg.setName("Test");
        reg.setEmail(email);
        reg.setPassword(password);
        reg.setRole("apprenant");
        authService.register(reg);

        // Active l email directement en base pour les tests
        User user = userRepository.findByEmail(email).orElseThrow();
        user.setEmailVerified(true);
        user.setEmailVerificationToken(null);

        // Generation d un vrai JWT
        String token = jwtService.generateToken(email);
        user.setToken(token);
        user.setTokenExpiresAt(LocalDateTime.now().plusMinutes(15));
        userRepository.save(user);

        return token;
    }

    // ─── Register ─────────────────────────────────────────────────────────────

    @Test
    void testRegisterSuccess() {
        RegisterRequest request = new RegisterRequest();
        request.setName("Poun");
        request.setEmail("poun@gmail.com");
        request.setPassword("Azerty1234!@");
        request.setRole("apprenant");

        Map<String, Object> response = authService.register(request);

        // Le nouveau message contient "Inscription réussie"
        Assertions.assertNotNull(response.get("message"));
        Assertions.assertTrue(response.get("message").toString().contains("Inscription réussie"));
        Assertions.assertTrue(userRepository.findByEmail("poun@gmail.com").isPresent());
    }

    @Test
    void testRegisterSendsVerificationToken() {
        RegisterRequest request = new RegisterRequest();
        request.setName("Poun");
        request.setEmail("poun@gmail.com");
        request.setPassword("Azerty1234!@");
        request.setRole("apprenant");

        Map<String, Object> response = authService.register(request);

        // Le token de verification est retourne
        Assertions.assertNotNull(response.get("emailVerificationToken"));
    }

    @Test
    void testRegisterDuplicateEmail() {
        registerDefaultUser();

        RegisterRequest request = new RegisterRequest();
        request.setName("Autre");
        request.setEmail("poun@gmail.com");
        request.setPassword("Azerty1234!@");
        request.setRole("apprenant");

        Map<String, Object> response = authService.register(request);
        Assertions.assertEquals("Email déjà utilisé", response.get("error"));
    }

    @Test
    void testRegisterWeakPassword() {
        RegisterRequest request = new RegisterRequest();
        request.setName("Poun");
        request.setEmail("poun@gmail.com");
        request.setPassword("123");

        Map<String, Object> response = authService.register(request);
        Assertions.assertNotNull(response.get("error"));
    }

    @Test
    void testRegisterNullPassword() {
        RegisterRequest request = new RegisterRequest();
        request.setName("Poun");
        request.setEmail("poun@gmail.com");
        request.setPassword(null);

        Map<String, Object> response = authService.register(request);
        Assertions.assertNotNull(response.get("error"));
    }

    // ─── Verify Email ─────────────────────────────────────────────────────────

    @Test
    void testVerifyEmailSuccess() {
        RegisterRequest request = new RegisterRequest();
        request.setName("Poun");
        request.setEmail("poun@gmail.com");
        request.setPassword("Azerty1234!@");
        request.setRole("apprenant");

        Map<String, Object> registerResponse = authService.register(request);
        String token = (String) registerResponse.get("emailVerificationToken");

        Map<String, Object> verifyResponse = authService.verifyEmail(token);

        Assertions.assertNotNull(verifyResponse.get("message"));
        Assertions.assertTrue(verifyResponse.get("message").toString().contains("confirmé"));

        User user = userRepository.findByEmail("poun@gmail.com").orElseThrow();
        Assertions.assertTrue(user.isEmailVerified());
    }

    @Test
    void testVerifyEmailInvalidToken() {
        Map<String, Object> response = authService.verifyEmail("token-invalide");
        Assertions.assertEquals("Token de verification invalide", response.get("error"));
    }

    @Test
    void testVerifyEmailNullToken() {
        Map<String, Object> response = authService.verifyEmail(null);
        Assertions.assertEquals("Token de verification manquant", response.get("error"));
    }

    // ─── Login ────────────────────────────────────────────────────────────────

    @Test
    void testLoginOkWithValidHmac() {
        registerDefaultUser();
        ClientProofResponse proof = buildValidProof();

        Map<String, Object> response = authService.login(toLoginRequest(proof));

        Assertions.assertEquals("Connexion réussie", response.get("message"));
        Assertions.assertNotNull(response.get("accessToken"));
        Assertions.assertEquals("poun@gmail.com", response.get("email"));
        Assertions.assertNotNull(response.get("expiresAt"));
    }

    @Test
    void testLoginKoEmailNotVerified() {
        // Inscription sans activation email
        RegisterRequest request = new RegisterRequest();
        request.setName("Poun");
        request.setEmail("poun@gmail.com");
        request.setPassword("Azerty1234!@");
        request.setRole("apprenant");
        authService.register(request);

        ClientProofResponse proof = buildValidProof();
        Map<String, Object> response = authService.login(toLoginRequest(proof));

        Assertions.assertEquals(
                "Email non confirmé. Veuillez vérifier votre boîte mail.",
                response.get("error")
        );
    }

    @Test
    void testLoginKoInvalidHmac() {
        registerDefaultUser();
        ClientProofResponse proof = buildValidProof();

        LoginRequest loginRequest = toLoginRequest(proof);
        loginRequest.setHmac("hmac-faux");

        Map<String, Object> response = authService.login(loginRequest);
        Assertions.assertEquals("HMAC invalide", response.get("error"));
    }

    @Test
    void testLoginKoExpiredTimestamp() {
        registerDefaultUser();
        ClientProofResponse proof = buildValidProof();

        LoginRequest loginRequest = toLoginRequest(proof);
        loginRequest.setTimestamp((System.currentTimeMillis() / 1000) - 1000);

        Map<String, Object> response = authService.login(loginRequest);
        Assertions.assertEquals("Requête expirée", response.get("error"));
    }

    @Test
    void testLoginKoFutureTimestamp() {
        registerDefaultUser();
        ClientProofResponse proof = buildValidProof();

        LoginRequest loginRequest = toLoginRequest(proof);
        loginRequest.setTimestamp((System.currentTimeMillis() / 1000) + 1000);

        Map<String, Object> response = authService.login(loginRequest);
        Assertions.assertEquals("Requête expirée", response.get("error"));
    }

    @Test
    void testLoginKoNonceAlreadyUsed() {
        registerDefaultUser();
        ClientProofResponse proof = buildValidProof();

        authService.login(toLoginRequest(proof));
        Map<String, Object> secondResponse = authService.login(toLoginRequest(proof));

        Assertions.assertEquals("Nonce déjà utilisé", secondResponse.get("error"));
    }

    @Test
    void testLoginKoUnknownUser() {
        ClientProofRequest request = new ClientProofRequest();
        request.setEmail("inconnu@gmail.com");
        request.setPassword("Azerty1234!@");

        ClientProofResponse proof = clientProofService.buildProof(request);
        Map<String, Object> response = authService.login(toLoginRequest(proof));

        Assertions.assertEquals("Utilisateur introuvable", response.get("error"));
    }

    @Test
    void testLoginKoWithoutEmail() {
        LoginRequest request = new LoginRequest();
        request.setNonce("nonce-test");
        request.setTimestamp(System.currentTimeMillis() / 1000);
        request.setHmac("abc");

        Map<String, Object> response = authService.login(request);
        Assertions.assertEquals("Email obligatoire", response.get("error"));
    }

    @Test
    void testLoginKoWithoutNonce() {
        LoginRequest request = new LoginRequest();
        request.setEmail("poun@gmail.com");
        request.setTimestamp(System.currentTimeMillis() / 1000);
        request.setHmac("abc");

        Map<String, Object> response = authService.login(request);
        Assertions.assertEquals("Nonce obligatoire", response.get("error"));
    }

    @Test
    void testLoginKoWithoutTimestamp() {
        LoginRequest request = new LoginRequest();
        request.setEmail("poun@gmail.com");
        request.setNonce("nonce-test");
        request.setHmac("abc");

        Map<String, Object> response = authService.login(request);
        Assertions.assertEquals("Timestamp obligatoire", response.get("error"));
    }

    @Test
    void testLoginKoWithoutHmac() {
        LoginRequest request = new LoginRequest();
        request.setEmail("poun@gmail.com");
        request.setNonce("nonce-test");
        request.setTimestamp(System.currentTimeMillis() / 1000);

        Map<String, Object> response = authService.login(request);
        Assertions.assertEquals("HMAC obligatoire", response.get("error"));
    }

    // ─── /me ──────────────────────────────────────────────────────────────────

    @Test
    void testGetMeOkWithToken() {
        registerDefaultUser();
        ClientProofResponse proof = buildValidProof();

        Map<String, Object> loginResponse = authService.login(toLoginRequest(proof));
        String token = (String) loginResponse.get("accessToken");

        Map<String, Object> meResponse = authService.getMe("Bearer " + token);

        Assertions.assertEquals("Poun", meResponse.get("name"));
        Assertions.assertEquals("poun@gmail.com", meResponse.get("email"));
    }

    @Test
    void testGetMeKoWithoutToken() {
        Map<String, Object> response = authService.getMe(null);
        Assertions.assertEquals("Token manquant ou invalide", response.get("error"));
    }

    @Test
    void testGetMeKoNoBearerPrefix() {
        Map<String, Object> response = authService.getMe("token-sans-bearer");
        Assertions.assertEquals("Token manquant ou invalide", response.get("error"));
    }

    @Test
    void testGetMeKoUnknownToken() {
        String fakeToken = jwtService.generateToken("inconnu@gmail.com");
        Map<String, Object> response = authService.getMe("Bearer " + fakeToken);
        Assertions.assertEquals("Utilisateur non trouvé pour ce token", response.get("error"));
    }

    @Test
    void testGetMeKoInvalidToken() {
        Map<String, Object> response = authService.getMe("Bearer token-invalide-pas-jwt");
        Assertions.assertEquals("Token manquant ou invalide", response.get("error"));
    }

    // ─── Logout ───────────────────────────────────────────────────────────────

    @Test
    void testLogoutOk() {
        registerDefaultUser();
        ClientProofResponse proof = buildValidProof();

        Map<String, Object> loginResponse = authService.login(toLoginRequest(proof));
        String token = (String) loginResponse.get("accessToken");

        Map<String, Object> logoutResponse = authService.logout("Bearer " + token);
        Assertions.assertEquals("Déconnexion réussie", logoutResponse.get("message"));

        User user = userRepository.findByEmail("poun@gmail.com").orElseThrow();
        Assertions.assertNull(user.getToken());
        Assertions.assertNull(user.getTokenExpiresAt());
    }

    @Test
    void testLogoutKoWithoutToken() {
        Map<String, Object> response = authService.logout(null);
        Assertions.assertEquals("Token manquant ou invalide", response.get("error"));
    }

    @Test
    void testLogoutKoNoBearerPrefix() {
        Map<String, Object> response = authService.logout("token-sans-bearer");
        Assertions.assertEquals("Token manquant ou invalide", response.get("error"));
    }

    @Test
    void testLogoutKoUnknownToken() {
        String fakeToken = jwtService.generateToken("inconnu@gmail.com");
        Map<String, Object> response = authService.logout("Bearer " + fakeToken);
        Assertions.assertEquals("Utilisateur non trouvé", response.get("error"));
    }

    // ─── changePassword ───────────────────────────────────────────────────────

    @Test
    void shouldChangePasswordSuccessfully() {
        String token = registerAndLogin("change.ok@test.com", "Ancien123!@AB");

        ChangePasswordRequest req = new ChangePasswordRequest();
        req.setOldPassword("Ancien123!@AB");
        req.setNewPassword("Nouveau456!@CD");

        Map<String, Object> response = authService.changePassword("Bearer " + token, req);

        Assertions.assertEquals("Mot de passe changé avec succès", response.get("message"));

        User user = userRepository.findByEmail("change.ok@test.com").orElseThrow();
        Assertions.assertEquals("Nouveau456!@CD",
                passwordCryptoService.decrypt(user.getPasswordEncrypted()));
    }

    @Test
    void shouldRefuseChangePasswordWhenOldPasswordIsWrong() {
        String token = registerAndLogin("change.wrong@test.com", "Ancien123!@AB");

        ChangePasswordRequest req = new ChangePasswordRequest();
        req.setOldPassword("MauvaisAncien!@1");
        req.setNewPassword("Nouveau456!@CD");

        Map<String, Object> response = authService.changePassword("Bearer " + token, req);

        Assertions.assertEquals("Ancien mot de passe incorrect", response.get("error"));
    }

    @Test
    void shouldRefuseChangePasswordWhenNewPasswordIsWeak() {
        String token = registerAndLogin("change.weak@test.com", "Ancien123!@AB");

        ChangePasswordRequest req = new ChangePasswordRequest();
        req.setOldPassword("Ancien123!@AB");
        req.setNewPassword("faible");

        Map<String, Object> response = authService.changePassword("Bearer " + token, req);

        Assertions.assertNotNull(response.get("error"));
    }

    @Test
    void shouldRefuseChangePasswordWhenTokenIsNull() {
        ChangePasswordRequest req = new ChangePasswordRequest();
        req.setOldPassword("Ancien123!@AB");
        req.setNewPassword("Nouveau456!@CD");

        Map<String, Object> response = authService.changePassword(null, req);

        Assertions.assertEquals("Token manquant ou invalide", response.get("error"));
    }

    @Test
    void shouldRefuseChangePasswordWhenTokenHasNoBearerPrefix() {
        ChangePasswordRequest req = new ChangePasswordRequest();
        req.setOldPassword("Ancien123!@AB");
        req.setNewPassword("Nouveau456!@CD");

        Map<String, Object> response = authService.changePassword("token-sans-bearer", req);

        Assertions.assertEquals("Token manquant ou invalide", response.get("error"));
    }

    @Test
    void shouldRefuseChangePasswordWhenRequestIsNull() {
        String token = jwtService.generateToken("test@test.com");
        Map<String, Object> response = authService.changePassword("Bearer " + token, null);

        Assertions.assertEquals("Requête invalide", response.get("error"));
    }

    @Test
    void shouldRefuseChangePasswordWhenOldPasswordIsBlank() {
        ChangePasswordRequest req = new ChangePasswordRequest();
        req.setOldPassword("   ");
        req.setNewPassword("Nouveau456!@CD");

        Map<String, Object> response = authService.changePassword("Bearer token-quelconque", req);

        Assertions.assertEquals("Ancien mot de passe obligatoire", response.get("error"));
    }

    @Test
    void shouldRefuseChangePasswordWhenNewPasswordIsBlank() {
        ChangePasswordRequest req = new ChangePasswordRequest();
        req.setOldPassword("Ancien123!@AB");
        req.setNewPassword("   ");

        Map<String, Object> response = authService.changePassword("Bearer token-quelconque", req);

        Assertions.assertEquals("Nouveau mot de passe obligatoire", response.get("error"));
    }

    @Test
    void shouldRefuseChangePasswordWhenTokenUnknown() {
        String fakeToken = jwtService.generateToken("inconnu@gmail.com");

        ChangePasswordRequest req = new ChangePasswordRequest();
        req.setOldPassword("Ancien123!@AB");
        req.setNewPassword("Nouveau456!@CD");

        Map<String, Object> response = authService.changePassword("Bearer " + fakeToken, req);

        Assertions.assertEquals("Utilisateur non trouvé pour ce token", response.get("error"));
    }

    @Test
    void shouldRefuseChangePasswordWhenTokenExpired() {
        ChangePasswordRequest req = new ChangePasswordRequest();
        req.setOldPassword("Ancien123!@AB");
        req.setNewPassword("Nouveau456!@CD");

        Map<String, Object> response = authService.changePassword("Bearer token-invalide", req);

        Assertions.assertEquals("Token manquant ou invalide", response.get("error"));
    }

    // ─── Register — branches de validation manquantes ────────────────────────

    @Test
    void testRegisterMissingName() {
        RegisterRequest request = new RegisterRequest();
        request.setName(null);
        request.setEmail("poun@gmail.com");
        request.setPassword("Azerty1234!@");
        request.setRole("apprenant");

        Map<String, Object> response = authService.register(request);
        Assertions.assertEquals("Nom obligatoire", response.get("error"));
    }

    @Test
    void testRegisterBlankName() {
        RegisterRequest request = new RegisterRequest();
        request.setName("   ");
        request.setEmail("poun@gmail.com");
        request.setPassword("Azerty1234!@");
        request.setRole("apprenant");

        Map<String, Object> response = authService.register(request);
        Assertions.assertEquals("Nom obligatoire", response.get("error"));
    }

    @Test
    void testRegisterMissingEmail() {
        RegisterRequest request = new RegisterRequest();
        request.setName("Poun");
        request.setEmail(null);
        request.setPassword("Azerty1234!@");
        request.setRole("apprenant");

        Map<String, Object> response = authService.register(request);
        Assertions.assertEquals("Email obligatoire", response.get("error"));
    }

    @Test
    void testRegisterInvalidRole() {
        RegisterRequest request = new RegisterRequest();
        request.setName("Poun");
        request.setEmail("poun@gmail.com");
        request.setPassword("Azerty1234!@");
        request.setRole("admin");

        Map<String, Object> response = authService.register(request);
        Assertions.assertEquals("Le role doit etre apprenant ou formateur", response.get("error"));
    }

    @Test
    void testRegisterWeakPasswordWithRole() {
        RegisterRequest request = new RegisterRequest();
        request.setName("Poun");
        request.setEmail("poun@gmail.com");
        request.setPassword("faible");
        request.setRole("apprenant");

        Map<String, Object> response = authService.register(request);
        Assertions.assertNotNull(response.get("error"));
        Assertions.assertFalse(response.get("error").toString().isEmpty());
    }

    @Test
    void testRegisterNullPasswordWithRole() {
        RegisterRequest request = new RegisterRequest();
        request.setName("Poun");
        request.setEmail("poun@gmail.com");
        request.setPassword(null);
        request.setRole("apprenant");

        Map<String, Object> response = authService.register(request);
        Assertions.assertNotNull(response.get("error"));
    }

    // ─── VerifyEmail — email déjà confirmé ───────────────────────────────────

    @Test
    void testVerifyEmailAlreadyVerified() {
        RegisterRequest request = new RegisterRequest();
        request.setName("Poun");
        request.setEmail("poun@gmail.com");
        request.setPassword("Azerty1234!@");
        request.setRole("apprenant");

        Map<String, Object> registerResponse = authService.register(request);
        String token = (String) registerResponse.get("emailVerificationToken");

        // Première vérification → succès
        authService.verifyEmail(token);

        // Deuxième vérification → email déjà confirmé
        Map<String, Object> secondResponse = authService.verifyEmail(null);
        Assertions.assertEquals("Token de verification manquant", secondResponse.get("error"));

        // Vérifier via un utilisateur déjà vérifié directement
        User user = userRepository.findByEmail("poun@gmail.com").orElseThrow();
        Assertions.assertTrue(user.isEmailVerified());

        // Simuler un token d'un utilisateur déjà vérifié
        user.setEmailVerificationToken("ancien-token");
        user.setEmailVerified(true);
        userRepository.save(user);

        Map<String, Object> alreadyVerified = authService.verifyEmail("ancien-token");
        Assertions.assertTrue(alreadyVerified.get("message").toString().contains("confirmé"));
    }

    // ─── AuthController Integration ───────────────────────────────────────────

    @SpringBootTest(classes = AuthApplication.class)
    @AutoConfigureMockMvc
    @ActiveProfiles("test")
    static class AuthControllerIntegrationTest {

        private static final String AUTH_URL = "/api/auth";

        @Autowired
        private ObjectMapper objectMapper;

        @Autowired
        private MockMvc mockMvc;

        @Autowired
        private HmacService hmacService;

        @Autowired
        private UserRepository userRepository;

        @Autowired
        private AuthNonceRepository authNonceRepository;

        private RegisterRequest buildRegisterRequest(String name, String email, String password) {
            RegisterRequest request = new RegisterRequest();
            request.setName(name);
            request.setEmail(email);
            request.setPassword(password);
            return request;
        }

        private LoginRequest buildLoginRequest(String email, String password) {
            LoginRequest request = new LoginRequest();
            long timestamp = System.currentTimeMillis() / 1000;
            String nonce = "nonce-" + System.nanoTime();
            String message = email + ":" + nonce + ":" + timestamp;
            String hmac = hmacService.hmacSha256(password, message);
            request.setEmail(email);
            request.setNonce(nonce);
            request.setTimestamp(timestamp);
            request.setHmac(hmac);
            return request;
        }

        private MvcResult postRegister(RegisterRequest request) throws Exception {
            return mockMvc.perform(
                    org.springframework.test.web.servlet.request.MockMvcRequestBuilders
                            .post(AUTH_URL + "/register")
                            .contentType(MediaType.APPLICATION_JSON)
                            .content(objectMapper.writeValueAsString(request))
            ).andReturn();
        }

        private MvcResult postLogin(LoginRequest request) throws Exception {
            return mockMvc.perform(
                    org.springframework.test.web.servlet.request.MockMvcRequestBuilders
                            .post(AUTH_URL + "/login")
                            .contentType(MediaType.APPLICATION_JSON)
                            .content(objectMapper.writeValueAsString(request))
            ).andReturn();
        }

        /**
         * Active l email d un utilisateur directement en base pour les tests.
         */
        private void activateEmail(String email) {
            User user = userRepository.findByEmail(email).orElseThrow();
            user.setEmailVerified(true);
            user.setEmailVerificationToken(null);
            userRepository.save(user);
        }

        @Test
        void testRegisterSuccess() throws Exception {
            RegisterRequest request = buildRegisterRequest("Jean", "jean.ctrl@gmail.com", "Azerty1234!@");
            MvcResult result = postRegister(request);
            Assertions.assertEquals(200, result.getResponse().getStatus());
        }

        @Test
        void testRegisterDuplicate() throws Exception {
            RegisterRequest request = buildRegisterRequest("Sara", "sara.ctrl@gmail.com", "Azerty1234!@");
            postRegister(request);
            MvcResult result = postRegister(request);
            Assertions.assertEquals(400, result.getResponse().getStatus());
        }

        @Test
        void testLoginSuccess() throws Exception {
            postRegister(buildRegisterRequest("Marie", "marie.ctrl@gmail.com", "Azerty1234!@"));
            activateEmail("marie.ctrl@gmail.com");
            MvcResult result = postLogin(buildLoginRequest("marie.ctrl@gmail.com", "Azerty1234!@"));
            Assertions.assertEquals(200, result.getResponse().getStatus());
            Assertions.assertTrue(result.getResponse().getContentAsString().contains("accessToken"));
        }

        @Test
        void testLoginUserNotFound() throws Exception {
            MvcResult result = postLogin(buildLoginRequest("no.ctrl@gmail.com", "Azerty1234!@"));
            Assertions.assertEquals(400, result.getResponse().getStatus());
        }

        @Test
        void testLoginInvalidHmac() throws Exception {
            postRegister(buildRegisterRequest("Paul", "paul.ctrl@gmail.com", "Azerty1234!@"));
            activateEmail("paul.ctrl@gmail.com");

            LoginRequest loginRequest = buildLoginRequest("paul.ctrl@gmail.com", "Azerty1234!@");
            loginRequest.setHmac("hmac-invalide");
            MvcResult result = postLogin(loginRequest);

            Assertions.assertEquals(400, result.getResponse().getStatus());
        }

        @Test
        void testMeWithoutToken() throws Exception {
            MvcResult result = mockMvc.perform(
                    org.springframework.test.web.servlet.request.MockMvcRequestBuilders
                            .get(AUTH_URL + "/me")
            ).andReturn();

            String body = result.getResponse().getContentAsString();
            Assertions.assertTrue(
                    body.contains("Token manquant") || body.contains("invalide")
            );
        }

        @Test
        void testLogoutWithoutToken() throws Exception {
            MvcResult result = mockMvc.perform(
                    org.springframework.test.web.servlet.request.MockMvcRequestBuilders
                            .post(AUTH_URL + "/logout")
            ).andReturn();

            String body = result.getResponse().getContentAsString();
            Assertions.assertTrue(
                    body.contains("Token manquant") || body.contains("invalide")
            );
        }

        @Test
        void testChangePasswordPutWithoutAuthHeader() throws Exception {
            String body = "{\"oldPassword\":\"Ancien123!@AB\",\"newPassword\":\"Nouveau456!@CD\"}";

            MvcResult result = mockMvc.perform(
                    org.springframework.test.web.servlet.request.MockMvcRequestBuilders
                            .put(AUTH_URL + "/change-password")
                            .contentType(MediaType.APPLICATION_JSON)
                            .content(body)
            ).andReturn();

            Assertions.assertEquals(400, result.getResponse().getStatus());
        }
    }
}