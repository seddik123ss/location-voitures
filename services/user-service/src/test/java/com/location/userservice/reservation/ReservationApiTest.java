package com.location.userservice.reservation;

import static com.location.userservice.ApiTestSupport.AGENT1;
import static com.location.userservice.ApiTestSupport.CLIENT1;
import static com.location.userservice.ApiTestSupport.CLIENT2;
import static com.location.userservice.ApiTestSupport.UNKNOWN;
import static com.location.userservice.ApiTestSupport.asAdmin;
import static com.location.userservice.ApiTestSupport.asClient1;
import static org.hamcrest.Matchers.hasSize;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyInt;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.when;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import feign.FeignException;
import feign.Request;
import feign.Response;
import feign.RetryableException;
import feign.codec.DecodeException;
import java.nio.charset.StandardCharsets;
import java.util.Map;
import org.junit.jupiter.api.Nested;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.boot.test.mock.mockito.MockBean;
import org.springframework.test.context.ActiveProfiles;
import org.springframework.test.web.servlet.MockMvc;

class ReservationApiTest {

    /** Mode simulation (RESERVATIONS_MOCK=true) : données de docs/demo-ids.md. */
    @Nested
    @SpringBootTest
    @AutoConfigureMockMvc
    @ActiveProfiles("test")
    class Simulation {

        @Autowired
        MockMvc mvc;

        @Test
        void client1_aDeuxReservations() throws Exception {
            mvc.perform(get("/api/users/" + CLIENT1 + "/reservations").with(asClient1()))
                    .andExpect(status().isOk())
                    .andExpect(jsonPath("$.total").value(2))
                    .andExpect(jsonPath("$.items", hasSize(2)))
                    .andExpect(jsonPath("$.items[0].status").value("PENDING"))
                    .andExpect(jsonPath("$.items[0].totalPrice").value(270.000))
                    .andExpect(jsonPath("$.items[0].currency").value("TND"));
        }

        @Test
        void agentSansReservation_listeVide() throws Exception {
            mvc.perform(get("/api/users/" + AGENT1 + "/reservations").with(asAdmin()))
                    .andExpect(status().isOk())
                    .andExpect(jsonPath("$.total").value(0));
        }

        @Test
        void clientNeVoitPasLHistoriqueDUnAutre_403() throws Exception {
            mvc.perform(get("/api/users/" + CLIENT2 + "/reservations").with(asClient1()))
                    .andExpect(status().isForbidden());
        }

        @Test
        void utilisateurInconnu_404() throws Exception {
            mvc.perform(get("/api/users/" + UNKNOWN + "/reservations").with(asAdmin()))
                    .andExpect(status().isNotFound());
        }
    }

    /** Démonstration du workshop OpenFeign : traduction des erreurs par FeignErrorHandler. */
    @Nested
    @SpringBootTest
    @AutoConfigureMockMvc
    @ActiveProfiles("test")
    class ErreursFeign {

        @Autowired
        MockMvc mvc;

        @MockBean
        ReservationHistory history;

        private static final Request REQUEST = Request.create(Request.HttpMethod.GET,
                "http://RENTAL-RESERVATION/internal/reservations", Map.of(), null, StandardCharsets.UTF_8, null);

        private static FeignException remoteStatus(int status) {
            Response response = Response.builder().status(status).reason("x").request(REQUEST)
                    .headers(Map.of()).body(new byte[0]).build();
            return FeignException.errorStatus("ReservationClient#findByUserId", response);
        }

        @Test
        void objetDistantIntrouvable_404() throws Exception {
            when(history.findByUserId(any(), anyInt(), anyInt())).thenThrow(remoteStatus(404));
            mvc.perform(get("/api/users/" + CLIENT1 + "/reservations").with(asAdmin()))
                    .andExpect(status().isNotFound())
                    .andExpect(jsonPath("$.error").value("NOT_FOUND"));
        }

        @Test
        void serviceArrete_503() throws Exception {
            when(history.findByUserId(any(), anyInt(), anyInt())).thenThrow(mock(RetryableException.class));
            mvc.perform(get("/api/users/" + CLIENT1 + "/reservations").with(asAdmin()))
                    .andExpect(status().isServiceUnavailable())
                    .andExpect(jsonPath("$.error").value("SERVICE_UNAVAILABLE"));
        }

        @Test
        void absentDEureka_503() throws Exception {
            when(history.findByUserId(any(), anyInt(), anyInt())).thenThrow(remoteStatus(503));
            mvc.perform(get("/api/users/" + CLIENT1 + "/reservations").with(asAdmin()))
                    .andExpect(status().isServiceUnavailable());
        }

        @Test
        void reponseIllisible_502() throws Exception {
            when(history.findByUserId(any(), anyInt(), anyInt())).thenThrow(mock(DecodeException.class));
            mvc.perform(get("/api/users/" + CLIENT1 + "/reservations").with(asAdmin()))
                    .andExpect(status().isBadGateway())
                    .andExpect(jsonPath("$.error").value("BAD_GATEWAY"));
        }

        @Test
        void autreErreurDistante_502() throws Exception {
            when(history.findByUserId(any(), anyInt(), anyInt())).thenThrow(remoteStatus(500));
            mvc.perform(get("/api/users/" + CLIENT1 + "/reservations").with(asAdmin()))
                    .andExpect(status().isBadGateway());
        }
    }
}
