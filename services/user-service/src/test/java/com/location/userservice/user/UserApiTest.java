package com.location.userservice.user;

import static com.location.userservice.ApiTestSupport.ADMIN1;
import static com.location.userservice.ApiTestSupport.CLIENT1;
import static com.location.userservice.ApiTestSupport.CLIENT2;
import static com.location.userservice.ApiTestSupport.UNKNOWN;
import static com.location.userservice.ApiTestSupport.as;
import static com.location.userservice.ApiTestSupport.asAdmin;
import static com.location.userservice.ApiTestSupport.asAgent;
import static com.location.userservice.ApiTestSupport.asClient1;
import static com.location.userservice.ApiTestSupport.asService;
import static org.hamcrest.Matchers.hasSize;
import static org.hamcrest.Matchers.nullValue;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.delete;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.put;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import org.junit.jupiter.api.Nested;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.http.MediaType;
import org.springframework.test.context.ActiveProfiles;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.transaction.annotation.Transactional;

@SpringBootTest
@AutoConfigureMockMvc
@ActiveProfiles("test")
@Transactional
class UserApiTest {

    private static final String NEW_ID = "2b6c1f0e-7a1d-4f7e-9a51-3c1d2e4f5a6b";

    @Autowired
    MockMvc mvc;

    private static String newUser(String id, String email, String phone, String role) {
        return """
                {"id":"%s","firstname":"Sami","lastname":"Ben Ali","email":"%s","phone":%s,"role":"%s"}
                """.formatted(id, email, phone == null ? "null" : "\"" + phone + "\"", role);
    }

    private static String update(String email, String phone, String role, boolean active) {
        return """
                {"firstname":"Client","lastname":"Un","email":"%s","phone":"%s","role":"%s","active":%s}
                """.formatted(email, phone, role, active);
    }

    @Nested
    class Publiques {
        @Test
        void health_sansToken_200() throws Exception {
            mvc.perform(get("/health")).andExpect(status().isOk()).andExpect(jsonPath("$.status").value("UP"));
        }

        @Test
        void api_sansToken_401_auFormatCommun() throws Exception {
            mvc.perform(get("/api/users"))
                    .andExpect(status().isUnauthorized())
                    .andExpect(jsonPath("$.status").value(401))
                    .andExpect(jsonPath("$.error").value("UNAUTHORIZED"))
                    .andExpect(jsonPath("$.details").isArray());
        }
    }

    @Nested
    class Liste {
        @Test
        void admin_paginationAuFormatCommun() throws Exception {
            mvc.perform(get("/api/users").param("page", "0").param("size", "2").with(asAdmin()))
                    .andExpect(status().isOk())
                    .andExpect(jsonPath("$.items", hasSize(2)))
                    .andExpect(jsonPath("$.page").value(0))
                    .andExpect(jsonPath("$.size").value(2))
                    .andExpect(jsonPath("$.total").value(4));
        }

        @Test
        void filtreParRoleEtNom() throws Exception {
            mvc.perform(get("/api/users").param("role", "CLIENT").with(asAgent()))
                    .andExpect(status().isOk())
                    .andExpect(jsonPath("$.total").value(2));
            mvc.perform(get("/api/users").param("name", "deux").with(asAdmin()))
                    .andExpect(jsonPath("$.total").value(1))
                    .andExpect(jsonPath("$.items[0].id").value(CLIENT2));
            mvc.perform(get("/api/users").param("name", "client un").with(asAdmin()))
                    .andExpect(jsonPath("$.items[0].id").value(CLIENT1));
        }

        @Test
        void client_403() throws Exception {
            mvc.perform(get("/api/users").with(asClient1()))
                    .andExpect(status().isForbidden())
                    .andExpect(jsonPath("$.error").value("FORBIDDEN"));
        }

        @Test
        void tailleDePageInvalide_400() throws Exception {
            mvc.perform(get("/api/users").param("size", "0").with(asAdmin())).andExpect(status().isBadRequest());
            mvc.perform(get("/api/users").param("role", "PILOTE").with(asAdmin())).andExpect(status().isBadRequest());
        }
    }

    @Nested
    class Lecture {
        @Test
        void clientLitSonProfil_avecPermisEtAdresse() throws Exception {
            mvc.perform(get("/api/users/" + CLIENT1).with(asClient1()))
                    .andExpect(status().isOk())
                    .andExpect(jsonPath("$.email").value("client1@test.tn"))
                    .andExpect(jsonPath("$.license.number").value("TN-2020-000111"))
                    .andExpect(jsonPath("$.license.expired").value(false))
                    .andExpect(jsonPath("$.addresses", hasSize(1)));
        }

        @Test
        void me_renvoieLeProfilDuToken() throws Exception {
            mvc.perform(get("/api/users/me").with(asClient1()))
                    .andExpect(status().isOk())
                    .andExpect(jsonPath("$.id").value(CLIENT1));
        }

        @Test
        void clientNePeutPasLireUnAutreProfil_403() throws Exception {
            mvc.perform(get("/api/users/" + CLIENT2).with(asClient1())).andExpect(status().isForbidden());
        }

        @Test
        void inconnu_404() throws Exception {
            mvc.perform(get("/api/users/" + UNKNOWN).with(asAdmin()))
                    .andExpect(status().isNotFound())
                    .andExpect(jsonPath("$.status").value(404))
                    .andExpect(jsonPath("$.error").value("NOT_FOUND"));
        }

        @Test
        void idMalForme_400() throws Exception {
            mvc.perform(get("/api/users/pas-un-uuid").with(asAdmin())).andExpect(status().isBadRequest());
        }
    }

    @Nested
    class Creation {
        @Test
        void admin_201_avecLocation() throws Exception {
            mvc.perform(post("/api/users").with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                            .content(newUser(NEW_ID, "Sami.BenAli@test.tn", "+21698765432", "AGENT")))
                    .andExpect(status().isCreated())
                    .andExpect(header().string("Location", "/api/users/" + NEW_ID))
                    .andExpect(jsonPath("$.email").value("sami.benali@test.tn"))
                    .andExpect(jsonPath("$.active").value(true))
                    .andExpect(jsonPath("$.license").value(nullValue()));
        }

        @Test
        void emailDejaUtilise_409() throws Exception {
            mvc.perform(post("/api/users").with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                            .content(newUser(NEW_ID, "CLIENT1@test.tn", null, "CLIENT")))
                    .andExpect(status().isConflict())
                    .andExpect(jsonPath("$.error").value("CONFLICT"))
                    .andExpect(jsonPath("$.details[0].field").value("email"));
        }

        @Test
        void idDejaUtilise_409() throws Exception {
            mvc.perform(post("/api/users").with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                            .content(newUser(CLIENT1, "autre@test.tn", null, "CLIENT")))
                    .andExpect(status().isConflict());
        }

        @Test
        void emailInvalide_422() throws Exception {
            mvc.perform(post("/api/users").with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                            .content(newUser(NEW_ID, "pas-un-email", null, "CLIENT")))
                    .andExpect(status().isUnprocessableEntity())
                    .andExpect(jsonPath("$.error").value("UNPROCESSABLE_ENTITY"))
                    .andExpect(jsonPath("$.details[0].field").value("email"));
        }

        @Test
        void telephoneInvalide_422() throws Exception {
            mvc.perform(post("/api/users").with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                            .content(newUser(NEW_ID, "sami@test.tn", "12-34", "CLIENT")))
                    .andExpect(status().isUnprocessableEntity())
                    .andExpect(jsonPath("$.details[0].field").value("phone"));
        }

        @Test
        void champObligatoireManquant_400() throws Exception {
            mvc.perform(post("/api/users").with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                            .content("{\"id\":\"" + NEW_ID + "\",\"email\":\"sami@test.tn\",\"role\":\"CLIENT\"}"))
                    .andExpect(status().isBadRequest())
                    .andExpect(jsonPath("$.status").value(400));
        }

        @Test
        void jsonIllisible_400() throws Exception {
            mvc.perform(post("/api/users").with(asAdmin()).contentType(MediaType.APPLICATION_JSON).content("{oups"))
                    .andExpect(status().isBadRequest());
        }

        @Test
        void utilisateurCreeSonPropreProfilClient_201() throws Exception {
            mvc.perform(post("/api/users").with(as(NEW_ID, "CLIENT")).contentType(MediaType.APPLICATION_JSON)
                            .content(newUser(NEW_ID, "nouveau@test.tn", null, "CLIENT")))
                    .andExpect(status().isCreated());
        }

        @Test
        void utilisateurNePeutPasSeDonnerLeRoleAdmin_403() throws Exception {
            mvc.perform(post("/api/users").with(as(NEW_ID, "CLIENT")).contentType(MediaType.APPLICATION_JSON)
                            .content(newUser(NEW_ID, "nouveau@test.tn", null, "ADMIN")))
                    .andExpect(status().isForbidden());
        }

        @Test
        void clientNePeutPasCreerLeProfilDUnAutre_403() throws Exception {
            mvc.perform(post("/api/users").with(asClient1()).contentType(MediaType.APPLICATION_JSON)
                            .content(newUser(NEW_ID, "nouveau@test.tn", null, "CLIENT")))
                    .andExpect(status().isForbidden());
        }
    }

    @Nested
    class Modification {
        @Test
        void clientModifieSonProfil_200() throws Exception {
            mvc.perform(put("/api/users/" + CLIENT1).with(asClient1()).contentType(MediaType.APPLICATION_JSON)
                            .content(update("client1@test.tn", "+21655000000", "CLIENT", true)))
                    .andExpect(status().isOk())
                    .andExpect(jsonPath("$.phone").value("+21655000000"));
        }

        @Test
        void clientNePeutPasChangerSonRole_403() throws Exception {
            mvc.perform(put("/api/users/" + CLIENT1).with(asClient1()).contentType(MediaType.APPLICATION_JSON)
                            .content(update("client1@test.tn", "+21620111111", "ADMIN", true)))
                    .andExpect(status().isForbidden());
        }

        @Test
        void adminDesactiveUnClient_200() throws Exception {
            mvc.perform(put("/api/users/" + CLIENT1).with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                            .content(update("client1@test.tn", "+21620111111", "CLIENT", false)))
                    .andExpect(status().isOk())
                    .andExpect(jsonPath("$.active").value(false));
        }

        @Test
        void emailDUnAutreUtilisateur_409() throws Exception {
            mvc.perform(put("/api/users/" + CLIENT1).with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                            .content(update("client2@test.tn", "+21620111111", "CLIENT", true)))
                    .andExpect(status().isConflict());
        }

        @Test
        void inconnu_404() throws Exception {
            mvc.perform(put("/api/users/" + UNKNOWN).with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                            .content(update("x@test.tn", "+21620111111", "CLIENT", true)))
                    .andExpect(status().isNotFound());
        }
    }

    @Nested
    class Suppression {
        @Test
        void admin_204_puis404() throws Exception {
            mvc.perform(delete("/api/users/" + CLIENT2).with(asAdmin())).andExpect(status().isNoContent());
            mvc.perform(get("/api/users/" + CLIENT2).with(asAdmin())).andExpect(status().isNotFound());
        }

        @Test
        void client_403() throws Exception {
            mvc.perform(delete("/api/users/" + CLIENT1).with(asClient1())).andExpect(status().isForbidden());
        }

        @Test
        void inconnu_404() throws Exception {
            mvc.perform(delete("/api/users/" + UNKNOWN).with(asAdmin())).andExpect(status().isNotFound());
        }
    }

    @Nested
    class Interne {
        @Test
        void serviceInternal_formatDeInternalApi() throws Exception {
            mvc.perform(get("/internal/users/" + CLIENT2).with(asService()))
                    .andExpect(status().isOk())
                    .andExpect(jsonPath("$.id").value(CLIENT2))
                    .andExpect(jsonPath("$.role").value("CLIENT"))
                    .andExpect(jsonPath("$.active").value(true))
                    .andExpect(jsonPath("$.license.number").value("TN-2016-000222"))
                    .andExpect(jsonPath("$.license.expiryDate").value("2026-06-30"))
                    .andExpect(jsonPath("$.license.verified").value(true))
                    .andExpect(jsonPath("$.license.id").doesNotExist());
        }

        @Test
        void utilisateurSansPermis_licenseNull() throws Exception {
            mvc.perform(get("/internal/users/" + ADMIN1).with(asService()))
                    .andExpect(status().isOk())
                    .andExpect(jsonPath("$.license").value(nullValue()));
        }

        @Test
        void inconnu_404() throws Exception {
            mvc.perform(get("/internal/users/" + UNKNOWN).with(asService())).andExpect(status().isNotFound());
        }

        @Test
        void sansRoleInternal_403() throws Exception {
            mvc.perform(get("/internal/users/" + CLIENT1).with(asAdmin())).andExpect(status().isForbidden());
        }

        @Test
        void sansToken_401() throws Exception {
            mvc.perform(get("/internal/users/" + CLIENT1)).andExpect(status().isUnauthorized());
        }
    }
}
