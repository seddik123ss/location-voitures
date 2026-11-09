package com.location.userservice.license;

import static com.location.userservice.ApiTestSupport.AGENT1;
import static com.location.userservice.ApiTestSupport.CLIENT1;
import static com.location.userservice.ApiTestSupport.CLIENT1_LICENSE;
import static com.location.userservice.ApiTestSupport.CLIENT2;
import static com.location.userservice.ApiTestSupport.CLIENT2_LICENSE;
import static com.location.userservice.ApiTestSupport.UNKNOWN;
import static com.location.userservice.ApiTestSupport.as;
import static com.location.userservice.ApiTestSupport.asAdmin;
import static com.location.userservice.ApiTestSupport.asAgent;
import static com.location.userservice.ApiTestSupport.asClient1;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.delete;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.patch;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.put;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import java.time.LocalDate;
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
class LicenseApiTest {

    @Autowired
    MockMvc mvc;

    private static String license(String userId, String number, String category, LocalDate issue, LocalDate expiry) {
        return """
                {"userId":"%s","number":"%s","category":"%s","issueDate":"%s","expiryDate":"%s"}
                """.formatted(userId, number, category, issue, expiry);
    }

    private static final LocalDate TODAY = LocalDate.now();

    @Test
    void creation_201_nonVerifie() throws Exception {
        mvc.perform(post("/api/licenses").with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                        .content(license(AGENT1, "tn-2022-000333", "B", TODAY.minusYears(2), TODAY.plusYears(8))))
                .andExpect(status().isCreated())
                .andExpect(header().exists("Location"))
                .andExpect(jsonPath("$.number").value("TN-2022-000333"))
                .andExpect(jsonPath("$.verified").value(false))
                .andExpect(jsonPath("$.userId").value(AGENT1));
    }

    @Test
    void permisDejaExpireALaCreation_422() throws Exception {
        mvc.perform(post("/api/licenses").with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                        .content(license(AGENT1, "TN-2010-000999", "B", LocalDate.parse("2010-06-30"),
                                LocalDate.parse("2020-06-30"))))
                .andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.error").value("UNPROCESSABLE_ENTITY"))
                .andExpect(jsonPath("$.details[0].field").value("expiryDate"));
    }

    @Test
    void numeroDejaEnregistre_409() throws Exception {
        mvc.perform(post("/api/licenses").with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                        .content(license(AGENT1, "TN-2020-000111", "B", TODAY.minusYears(1), TODAY.plusYears(9))))
                .andExpect(status().isConflict())
                .andExpect(jsonPath("$.details[0].field").value("number"));
    }

    @Test
    void deuxiemePermisPourLeMemeUtilisateur_409() throws Exception {
        mvc.perform(post("/api/licenses").with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                        .content(license(CLIENT1, "TN-2024-000444", "B", TODAY.minusYears(1), TODAY.plusYears(9))))
                .andExpect(status().isConflict())
                .andExpect(jsonPath("$.details[0].field").value("userId"));
    }

    @Test
    void delivranceDansLeFutur_422() throws Exception {
        mvc.perform(post("/api/licenses").with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                        .content(license(AGENT1, "TN-2030-000555", "B", TODAY.plusDays(5), TODAY.plusYears(10))))
                .andExpect(status().isUnprocessableEntity());
    }

    @Test
    void categorieInconnue_422() throws Exception {
        mvc.perform(post("/api/licenses").with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                        .content(license(AGENT1, "TN-2022-000666", "Z", TODAY.minusYears(1), TODAY.plusYears(9))))
                .andExpect(status().isUnprocessableEntity());
    }

    @Test
    void utilisateurInconnu_404() throws Exception {
        mvc.perform(post("/api/licenses").with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                        .content(license(UNKNOWN, "TN-2022-000777", "B", TODAY.minusYears(1), TODAY.plusYears(9))))
                .andExpect(status().isNotFound());
    }

    @Test
    void clientAjouteSonProprePermis_201_maisPasCeluiDUnAutre_403() throws Exception {
        mvc.perform(post("/api/licenses").with(as(AGENT1, "CLIENT")).contentType(MediaType.APPLICATION_JSON)
                        .content(license(AGENT1, "TN-2022-000888", "B", TODAY.minusYears(1), TODAY.plusYears(9))))
                .andExpect(status().isCreated());
        mvc.perform(post("/api/licenses").with(asClient1()).contentType(MediaType.APPLICATION_JSON)
                        .content(license(AGENT1, "TN-2022-000889", "B", TODAY.minusYears(1), TODAY.plusYears(9))))
                .andExpect(status().isForbidden());
    }

    @Test
    void lecture_proprietaire200_autreClient403_agent200() throws Exception {
        mvc.perform(get("/api/licenses/" + CLIENT1_LICENSE).with(asClient1())).andExpect(status().isOk());
        mvc.perform(get("/api/licenses/" + CLIENT2_LICENSE).with(asClient1())).andExpect(status().isForbidden());
        mvc.perform(get("/api/licenses/" + CLIENT2_LICENSE).with(asAgent()))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.expired").value(true));
    }

    @Test
    void liste_filtreParUtilisateur() throws Exception {
        mvc.perform(get("/api/licenses").param("userId", CLIENT2).with(asAgent()))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.total").value(1))
                .andExpect(jsonPath("$.items[0].id").value(CLIENT2_LICENSE));
        mvc.perform(get("/api/licenses").with(asClient1())).andExpect(status().isForbidden());
    }

    @Test
    void modification_annuleLaVerification() throws Exception {
        mvc.perform(put("/api/licenses/" + CLIENT1_LICENSE).with(asClient1()).contentType(MediaType.APPLICATION_JSON)
                        .content("""
                                {"number":"TN-2020-000111","category":"BE","issueDate":"2020-03-15","expiryDate":"2030-03-15"}
                                """))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.category").value("BE"))
                .andExpect(jsonPath("$.verified").value(false));
    }

    @Test
    void verification_parAgent200_permisExpire422_parClient403() throws Exception {
        mvc.perform(put("/api/licenses/" + CLIENT1_LICENSE).with(asAdmin()).contentType(MediaType.APPLICATION_JSON)
                        .content("""
                                {"number":"TN-2020-000111","category":"BE","issueDate":"2020-03-15","expiryDate":"2030-03-15"}
                                """))
                .andExpect(jsonPath("$.verified").value(false));
        mvc.perform(patch("/api/licenses/" + CLIENT1_LICENSE + "/verify").with(asAgent()))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.verified").value(true));
        mvc.perform(patch("/api/licenses/" + CLIENT2_LICENSE + "/verify").with(asAgent()))
                .andExpect(status().isUnprocessableEntity());
        mvc.perform(patch("/api/licenses/" + CLIENT1_LICENSE + "/verify").with(asClient1()))
                .andExpect(status().isForbidden());
    }

    @Test
    void suppression_proprietaire204_inconnu404() throws Exception {
        mvc.perform(delete("/api/licenses/" + CLIENT1_LICENSE).with(asClient1())).andExpect(status().isNoContent());
        mvc.perform(get("/api/licenses/" + CLIENT1_LICENSE).with(asAdmin())).andExpect(status().isNotFound());
        mvc.perform(delete("/api/licenses/" + UNKNOWN).with(asAdmin())).andExpect(status().isNotFound());
    }
}
