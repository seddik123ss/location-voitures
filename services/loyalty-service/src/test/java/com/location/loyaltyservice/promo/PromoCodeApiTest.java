package com.location.loyaltyservice.promo;

import static com.location.loyaltyservice.ApiTestSupport.BIENVENUE10;
import static com.location.loyaltyservice.ApiTestSupport.CLIENT1;
import static com.location.loyaltyservice.ApiTestSupport.CLIENT2;
import static com.location.loyaltyservice.ApiTestSupport.FLASH100;
import static com.location.loyaltyservice.ApiTestSupport.R_CONFIRMED;
import static com.location.loyaltyservice.ApiTestSupport.R_PENDING;
import static com.location.loyaltyservice.ApiTestSupport.UNKNOWN;
import static com.location.loyaltyservice.ApiTestSupport.as;
import static com.location.loyaltyservice.ApiTestSupport.asAdmin;
import static com.location.loyaltyservice.ApiTestSupport.asAgent;
import static com.location.loyaltyservice.ApiTestSupport.asClient1;
import static com.location.loyaltyservice.ApiTestSupport.asClient2;
import static com.location.loyaltyservice.ApiTestSupport.asService;
import static com.location.loyaltyservice.ApiTestSupport.httpError;
import static com.location.loyaltyservice.ApiTestSupport.unreachable;
import static com.location.loyaltyservice.ApiTestSupport.unreadable;
import static org.hamcrest.Matchers.endsWith;
import static org.hamcrest.Matchers.everyItem;
import static org.hamcrest.Matchers.not;
import static org.hamcrest.Matchers.hasItem;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.Mockito.doReturn;
import static org.mockito.Mockito.doThrow;
import static org.mockito.Mockito.when;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.delete;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.put;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.location.loyaltyservice.IntegrationTestBase;
import com.location.loyaltyservice.remote.RemoteUser;
import java.util.UUID;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.http.MediaType;
import org.springframework.test.web.servlet.ResultActions;
import org.springframework.test.web.servlet.request.MockHttpServletRequestBuilder;
import org.springframework.test.web.servlet.request.RequestPostProcessor;

/** Codes promo (sections 5, 6, 7) : chaque ligne du tableau de la section 7 a son test. */
class PromoCodeApiTest extends IntegrationTestBase {

    @Autowired
    private ObjectMapper objectMapper;

    private ResultActions check(RequestPostProcessor who, String code, String amount) throws Exception {
        return mvc.perform(json(post("/api/promo-codes/check"), "{\"code\":\"" + code + "\",\"amount\":" + amount + "}")
                .with(who));
    }

    private ResultActions validate(String code, String userId, String amount) throws Exception {
        return mvc.perform(json(post("/internal/promo-codes/validate"),
                "{\"code\":\"" + code + "\",\"userId\":\"" + userId + "\",\"amount\":" + amount + "}").with(asService()));
    }

    private ResultActions apply(String code, String userId, String reservationId, String amount) throws Exception {
        return mvc.perform(json(post("/internal/promo-codes/apply"), "{\"code\":\"" + code + "\",\"userId\":\"" + userId
                + "\",\"reservationId\":\"" + reservationId + "\",\"amount\":" + amount + "}").with(asService()));
    }

    private static MockHttpServletRequestBuilder json(MockHttpServletRequestBuilder request, String body) {
        return request.contentType(MediaType.APPLICATION_JSON).content(body);
    }

    private void userIsActive(String userId, boolean active) {
        when(userClient.getUser(any())).thenReturn(new RemoteUser(UUID.fromString(userId), "CLIENT", active));
    }

    private String createCode(String body) throws Exception {
        String response = mvc.perform(json(post("/api/promo-codes"), body).with(asAdmin()))
                .andExpect(status().isCreated()).andReturn().getResponse().getContentAsString();
        JsonNode node = objectMapper.readTree(response);
        return node.get("id").asText();
    }

    // ---------------------------------------------------------------- aperçu client (/check) et section 7

    @Test
    void validCodeGivesTheDiscountWithoutConsumingIt() throws Exception {
        check(asClient1(), "bienvenue10", "450.000")
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.code").value("BIENVENUE10"))
                .andExpect(jsonPath("$.amountBefore").value(450.0))
                .andExpect(jsonPath("$.discount").value(45.0))
                .andExpect(jsonPath("$.amountAfter").value(405.0));
        mvc.perform(get("/api/promo-codes/" + BIENVENUE10).with(asAgent()))
                .andExpect(jsonPath("$.usedCount").value(0));
    }

    @Test
    void unknownCodeIs404() throws Exception {
        check(asClient1(), "INCONNU", "100").andExpect(status().isNotFound())
                .andExpect(jsonPath("$.details[0].field").value("code"));
    }

    @Test
    void expiredCodeIs422() throws Exception {
        check(asClient1(), "ETE2026", "100").andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("code"))
                .andExpect(jsonPath("$.message").value(endsWith("2026-09-30")));
    }

    @Test
    void notYetValidCodeIs422() throws Exception {
        createCode("{\"code\":\"NOEL2026\",\"discountType\":\"PERCENT\",\"discountValue\":10,"
                + "\"validFrom\":\"2026-12-20\",\"validUntil\":\"2026-12-31\"}");
        check(asClient1(), "NOEL2026", "100").andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("code"));
    }

    @Test
    void deactivatedCodeIs422() throws Exception {
        mvc.perform(json(put("/api/promo-codes/" + BIENVENUE10), "{\"discountType\":\"PERCENT\",\"discountValue\":10,"
                        + "\"validFrom\":\"2026-01-01\",\"validUntil\":\"2027-12-31\",\"active\":false}").with(asAdmin()))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.state").value("INACTIVE"));
        check(asClient1(), "BIENVENUE10", "100").andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("code"));
    }

    @Test
    void amountBelowMinimumIs422() throws Exception {
        check(asClient1(), "WEEKEND", "150").andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("amount"));
        check(asClient1(), "WEEKEND", "250").andExpect(status().isOk())
                .andExpect(jsonPath("$.amountAfter").value(220.0));
    }

    @Test
    void exhaustedCodeIs409() throws Exception {
        check(asClient1(), "FLASH100", "100").andExpect(status().isConflict())
                .andExpect(jsonPath("$.details[0].field").value("code"));
    }

    @Test
    void goldOnlyCodeIs403ForBronzeClient() throws Exception {
        check(asClient2(), "GOLD50", "300").andExpect(status().isForbidden())
                .andExpect(jsonPath("$.details[0].field").value("code"));
        check(asClient1(), "GOLD50", "300").andExpect(status().isOk())
                .andExpect(jsonPath("$.discount").value(50.0));
    }

    @Test
    void personalVoucherOfAnotherClientIs403() throws Exception {
        check(asClient2(), "LOY-DEMO40", "300").andExpect(status().isForbidden())
                .andExpect(jsonPath("$.details[0].field").value("code"));
        check(asClient1(), "LOY-DEMO40", "300").andExpect(status().isOk());
    }

    @Test
    void clientWithoutLoyaltyAccountIs422() throws Exception {
        check(as(UNKNOWN, "CLIENT"), "BIENVENUE10", "100").andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("userId"));
    }

    @Test
    void checkIsForClientsOnly() throws Exception {
        check(asAgent(), "BIENVENUE10", "100").andExpect(status().isForbidden());
    }

    // ---------------------------------------------------------------- gestion (ADMIN, AGENT en lecture)

    @Test
    void adminCreatesACodeInUpperCase() throws Exception {
        mvc.perform(json(post("/api/promo-codes"), "{\"code\":\"printemps20\",\"discountType\":\"PERCENT\","
                        + "\"discountValue\":20,\"minTier\":\"SILVER\",\"validFrom\":\"2026-12-01\","
                        + "\"validUntil\":\"2027-03-31\",\"maxUses\":500}").with(asAdmin()))
                .andExpect(status().isCreated())
                .andExpect(header().string("Location", org.hamcrest.Matchers.startsWith("/api/promo-codes/")))
                .andExpect(jsonPath("$.code").value("PRINTEMPS20"))
                .andExpect(jsonPath("$.maxUsesPerUser").value(1))
                .andExpect(jsonPath("$.usedCount").value(0))
                .andExpect(jsonPath("$.state").value("UPCOMING"));
    }

    @Test
    void duplicateCodeIs409() throws Exception {
        mvc.perform(json(post("/api/promo-codes"), "{\"code\":\"Bienvenue10\",\"discountType\":\"PERCENT\","
                        + "\"discountValue\":5,\"validFrom\":\"2026-12-01\",\"validUntil\":\"2027-03-31\"}").with(asAdmin()))
                .andExpect(status().isConflict())
                .andExpect(jsonPath("$.details[0].field").value("code"));
    }

    @Test
    void endDateMustBeAfterStartDate() throws Exception {
        mvc.perform(json(post("/api/promo-codes"), "{\"code\":\"DATES\",\"discountType\":\"AMOUNT\","
                        + "\"discountValue\":5,\"validFrom\":\"2026-12-01\",\"validUntil\":\"2026-12-01\"}").with(asAdmin()))
                .andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("validUntil"));
    }

    @Test
    void percentAbove50Is422() throws Exception {
        mvc.perform(json(post("/api/promo-codes"), "{\"code\":\"TROP60\",\"discountType\":\"PERCENT\","
                        + "\"discountValue\":60,\"validFrom\":\"2026-12-01\",\"validUntil\":\"2027-01-01\"}").with(asAdmin()))
                .andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("discountValue"));
    }

    @Test
    void zeroAmountIs422() throws Exception {
        mvc.perform(json(post("/api/promo-codes"), "{\"code\":\"ZERO\",\"discountType\":\"AMOUNT\","
                        + "\"discountValue\":0,\"validFrom\":\"2026-12-01\",\"validUntil\":\"2027-01-01\"}").with(asAdmin()))
                .andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("discountValue"));
    }

    @Test
    void invalidCodeFormatIs422() throws Exception {
        mvc.perform(json(post("/api/promo-codes"), "{\"code\":\"A B\",\"discountType\":\"AMOUNT\","
                        + "\"discountValue\":5,\"validFrom\":\"2026-12-01\",\"validUntil\":\"2027-01-01\"}").with(asAdmin()))
                .andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("code"));
    }

    @Test
    void missingFieldsAre400() throws Exception {
        mvc.perform(json(post("/api/promo-codes"), "{\"discountType\":\"AMOUNT\",\"discountValue\":5}").with(asAdmin()))
                .andExpect(status().isBadRequest())
                .andExpect(jsonPath("$.error").value("BAD_REQUEST"));
    }

    @Test
    void onlyAdminCreatesCodes() throws Exception {
        mvc.perform(json(post("/api/promo-codes"), "{\"code\":\"AGENT1\",\"discountType\":\"AMOUNT\","
                        + "\"discountValue\":5,\"validFrom\":\"2026-12-01\",\"validUntil\":\"2027-01-01\"}").with(asAgent()))
                .andExpect(status().isForbidden());
        mvc.perform(get("/api/promo-codes").with(asClient1())).andExpect(status().isForbidden());
    }

    @Test
    void listHidesPersonalVouchers() throws Exception {
        mvc.perform(get("/api/promo-codes").with(asAgent()))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.total").value(5))
                .andExpect(jsonPath("$.items[*].code").value(hasItem("FLASH100")))
                .andExpect(jsonPath("$.items[*].code").value(everyItem(not("LOY-DEMO40"))));
    }

    @Test
    void unusedCodeCanBeDeletedButUsedCodeCannot() throws Exception {
        String id = createCode("{\"code\":\"JETABLE\",\"discountType\":\"AMOUNT\",\"discountValue\":5,"
                + "\"validFrom\":\"2026-11-01\",\"validUntil\":\"2027-01-01\"}");
        mvc.perform(delete("/api/promo-codes/" + id).with(asAdmin())).andExpect(status().isNoContent());
        mvc.perform(get("/api/promo-codes/" + id).with(asAdmin())).andExpect(status().isNotFound());

        mvc.perform(delete("/api/promo-codes/" + FLASH100).with(asAdmin()))
                .andExpect(status().isConflict())
                .andExpect(jsonPath("$.details[0].field").value("id"));
    }

    // ---------------------------------------------------------------- routes internes (reservation-service)

    @Test
    void validateChecksTheUserInUserService() throws Exception {
        userIsActive(CLIENT1, true);
        validate("BIENVENUE10", CLIENT1, "270").andExpect(status().isOk())
                .andExpect(jsonPath("$.amountAfter").value(243.0));

        userIsActive(CLIENT1, false);
        validate("BIENVENUE10", CLIENT1, "270").andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("userId"));
    }

    @Test
    void validateUnknownUserIs422() throws Exception {
        when(userClient.getUser(any())).thenThrow(httpError(404));
        validate("BIENVENUE10", CLIENT1, "270").andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("userId"));
    }

    @Test
    void userServiceDownIs503() throws Exception {
        doThrow(unreachable()).when(userClient).getUser(any());
        validate("BIENVENUE10", CLIENT1, "270").andExpect(status().isServiceUnavailable());

        doThrow(httpError(503)).when(userClient).getUser(any());
        validate("BIENVENUE10", CLIENT1, "270").andExpect(status().isServiceUnavailable());
    }

    @Test
    void unreadableUserServiceResponseIs502() throws Exception {
        doThrow(unreadable()).when(userClient).getUser(any());
        validate("BIENVENUE10", CLIENT1, "270").andExpect(status().isBadGateway());

        doReturn(new RemoteUser(null, null, null)).when(userClient).getUser(any());
        validate("BIENVENUE10", CLIENT1, "270").andExpect(status().isBadGateway());
    }

    @Test
    void applyIsIdempotentPerReservation() throws Exception {
        userIsActive(CLIENT1, true);
        apply("BIENVENUE10", CLIENT1, R_PENDING, "270").andExpect(status().isCreated())
                .andExpect(jsonPath("$.code").value("BIENVENUE10"))
                .andExpect(jsonPath("$.discount").value(27.0))
                .andExpect(jsonPath("$.amountAfter").value(243.0));
        apply("BIENVENUE10", CLIENT1, R_PENDING, "270").andExpect(status().isOk())
                .andExpect(jsonPath("$.reservationId").value(R_PENDING));

        mvc.perform(get("/api/promo-codes/" + BIENVENUE10).with(asAdmin()))
                .andExpect(jsonPath("$.usedCount").value(1));
    }

    @Test
    void sameClientCannotReuseACodeOnAnotherReservation() throws Exception {
        userIsActive(CLIENT1, true);
        apply("BIENVENUE10", CLIENT1, R_PENDING, "270").andExpect(status().isCreated());
        apply("BIENVENUE10", CLIENT1, R_CONFIRMED, "450").andExpect(status().isConflict())
                .andExpect(jsonPath("$.details[0].field").value("code"));
    }

    @Test
    void anotherCodeOnTheSameReservationIs409() throws Exception {
        userIsActive(CLIENT1, true);
        apply("BIENVENUE10", CLIENT1, R_PENDING, "270").andExpect(status().isCreated());
        apply("GOLD50", CLIENT1, R_PENDING, "270").andExpect(status().isConflict())
                .andExpect(jsonPath("$.details[0].field").value("reservationId"));
    }

    @Test
    void personalVoucherIsSingleUse() throws Exception {
        userIsActive(CLIENT1, true);
        apply("LOY-DEMO40", CLIENT1, R_PENDING, "270").andExpect(status().isCreated())
                .andExpect(jsonPath("$.amountAfter").value(230.0));
        mvc.perform(get("/api/loyalty/me/vouchers").with(asClient1()))
                .andExpect(jsonPath("$.length()").value(0));
        apply("LOY-DEMO40", CLIENT1, R_CONFIRMED, "450").andExpect(status().isConflict());
    }

    @Test
    void internalRoutesRefuseUserTokensAndAnonymousCalls() throws Exception {
        mvc.perform(json(post("/internal/promo-codes/validate"),
                        "{\"code\":\"BIENVENUE10\",\"userId\":\"" + CLIENT2 + "\",\"amount\":100}").with(asClient2()))
                .andExpect(status().isForbidden());
        mvc.perform(json(post("/internal/promo-codes/validate"),
                        "{\"code\":\"BIENVENUE10\",\"userId\":\"" + CLIENT2 + "\",\"amount\":100}"))
                .andExpect(status().isUnauthorized());
    }
}
