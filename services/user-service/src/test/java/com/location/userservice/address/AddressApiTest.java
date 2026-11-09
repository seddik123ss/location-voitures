package com.location.userservice.address;

import static com.location.userservice.ApiTestSupport.CLIENT1;
import static com.location.userservice.ApiTestSupport.CLIENT1_ADDRESS;
import static com.location.userservice.ApiTestSupport.CLIENT2;
import static com.location.userservice.ApiTestSupport.UNKNOWN;
import static com.location.userservice.ApiTestSupport.asAdmin;
import static com.location.userservice.ApiTestSupport.asClient1;
import static org.hamcrest.Matchers.hasSize;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.delete;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.put;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

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
class AddressApiTest {

    private static final String BODY = """
            {"street":"3 Rue Ibn Khaldoun","city":"Sfax","zipCode":"3000"}
            """;

    @Autowired
    MockMvc mvc;

    @Test
    void crud_parLeProprietaire() throws Exception {
        mvc.perform(post("/api/users/" + CLIENT1 + "/addresses").with(asClient1())
                        .contentType(MediaType.APPLICATION_JSON).content(BODY))
                .andExpect(status().isCreated())
                .andExpect(header().exists("Location"))
                .andExpect(jsonPath("$.city").value("Sfax"));
        mvc.perform(get("/api/users/" + CLIENT1 + "/addresses").with(asClient1()))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$", hasSize(2)));
        mvc.perform(put("/api/users/" + CLIENT1 + "/addresses/" + CLIENT1_ADDRESS).with(asClient1())
                        .contentType(MediaType.APPLICATION_JSON).content(BODY))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.street").value("3 Rue Ibn Khaldoun"));
        mvc.perform(delete("/api/users/" + CLIENT1 + "/addresses/" + CLIENT1_ADDRESS).with(asClient1()))
                .andExpect(status().isNoContent());
    }

    @Test
    void codePostalInvalide_422() throws Exception {
        mvc.perform(post("/api/users/" + CLIENT1 + "/addresses").with(asClient1())
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"street\":\"x\",\"city\":\"Tunis\",\"zipCode\":\"10\"}"))
                .andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("zipCode"));
    }

    @Test
    void adresseDUnAutreUtilisateur_404_etClientEtranger_403() throws Exception {
        mvc.perform(get("/api/users/" + CLIENT2 + "/addresses/" + CLIENT1_ADDRESS).with(asAdmin()))
                .andExpect(status().isNotFound());
        mvc.perform(get("/api/users/" + CLIENT2 + "/addresses").with(asClient1()))
                .andExpect(status().isForbidden());
    }

    @Test
    void utilisateurInconnu_404() throws Exception {
        mvc.perform(get("/api/users/" + UNKNOWN + "/addresses").with(asAdmin())).andExpect(status().isNotFound());
    }
}
