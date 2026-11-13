package com.location.loyaltyservice.account;

import static com.location.loyaltyservice.ApiTestSupport.CLIENT1;
import static com.location.loyaltyservice.ApiTestSupport.CLIENT2;
import static com.location.loyaltyservice.ApiTestSupport.UNKNOWN;
import static com.location.loyaltyservice.ApiTestSupport.as;
import static com.location.loyaltyservice.ApiTestSupport.asAdmin;
import static com.location.loyaltyservice.ApiTestSupport.asAgent;
import static com.location.loyaltyservice.ApiTestSupport.asClient1;
import static com.location.loyaltyservice.ApiTestSupport.asClient2;
import static com.location.loyaltyservice.ApiTestSupport.asService;
import static org.hamcrest.Matchers.hasItem;
import static org.hamcrest.Matchers.matchesPattern;
import static org.hamcrest.Matchers.nullValue;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import com.location.loyaltyservice.IntegrationTestBase;
import org.junit.jupiter.api.Test;
import org.springframework.http.MediaType;

/** Comptes et points (section 5) + sécurité (section 13). */
class LoyaltyApiTest extends IntegrationTestBase {

    @Test
    void client1SeesHisGoldAccount() throws Exception {
        mvc.perform(get("/api/loyalty/me").with(asClient1()))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.userId").value(CLIENT1))
                .andExpect(jsonPath("$.balance").value(1240))
                .andExpect(jsonPath("$.lifetimePoints").value(1640))
                .andExpect(jsonPath("$.tier").value("GOLD"))
                .andExpect(jsonPath("$.nextTier").value(nullValue()))
                .andExpect(jsonPath("$.pointsToNextTier").value(0))
                .andExpect(jsonPath("$.progress").value(1.0));
    }

    @Test
    void client2SeesPointsMissingForSilver() throws Exception {
        mvc.perform(get("/api/loyalty/me").with(asClient2()))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.tier").value("BRONZE"))
                .andExpect(jsonPath("$.nextTier").value("SILVER"))
                .andExpect(jsonPath("$.pointsToNextTier").value(320))
                .andExpect(jsonPath("$.progress").value(0.36));
    }

    @Test
    void meWithoutAccountIs404() throws Exception {
        mvc.perform(get("/api/loyalty/me").with(as(UNKNOWN, "CLIENT")))
                .andExpect(status().isNotFound())
                .andExpect(jsonPath("$.error").value("NOT_FOUND"));
    }

    @Test
    void historyIsPaginatedNewestFirst() throws Exception {
        mvc.perform(get("/api/loyalty/me/transactions?page=0&size=2").with(asClient1()))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.total").value(4))
                .andExpect(jsonPath("$.size").value(2))
                .andExpect(jsonPath("$.items.length()").value(2))
                .andExpect(jsonPath("$.items[0].type").value("REDEEM"))
                .andExpect(jsonPath("$.items[0].points").value(-400))
                .andExpect(jsonPath("$.items[1].type").value("EARN"));
    }

    @Test
    void invalidPageSizeIs400() throws Exception {
        mvc.perform(get("/api/loyalty/me/transactions?size=500").with(asClient1()))
                .andExpect(status().isBadRequest());
    }

    @Test
    void redeemCreatesAVoucherWithoutLoweringTheTier() throws Exception {
        mvc.perform(post("/api/loyalty/me/redeem").with(asClient1())
                        .contentType(MediaType.APPLICATION_JSON).content("{\"points\":200}"))
                .andExpect(status().isCreated())
                .andExpect(header().exists("Location"))
                .andExpect(jsonPath("$.code").value(matchesPattern("^LOY-[A-Z0-9]{6}$")))
                .andExpect(jsonPath("$.discountType").value("AMOUNT"))
                .andExpect(jsonPath("$.discountValue").value(20.0))
                .andExpect(jsonPath("$.maxUses").value(1))
                .andExpect(jsonPath("$.ownerUserId").value(CLIENT1))
                .andExpect(jsonPath("$.validUntil").value("2027-02-18"));

        mvc.perform(get("/api/loyalty/me").with(asClient1()))
                .andExpect(jsonPath("$.balance").value(1040))
                .andExpect(jsonPath("$.lifetimePoints").value(1640))
                .andExpect(jsonPath("$.tier").value("GOLD"));
        mvc.perform(get("/api/loyalty/me/vouchers").with(asClient1()))
                .andExpect(jsonPath("$.length()").value(2));
    }

    @Test
    void redeemMustBeAMultipleOf100() throws Exception {
        mvc.perform(post("/api/loyalty/me/redeem").with(asClient1())
                        .contentType(MediaType.APPLICATION_JSON).content("{\"points\":150}"))
                .andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("points"));
    }

    @Test
    void redeemMoreThanTheBalanceIs422() throws Exception {
        mvc.perform(post("/api/loyalty/me/redeem").with(asClient2())
                        .contentType(MediaType.APPLICATION_JSON).content("{\"points\":200}"))
                .andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("points"));
    }

    @Test
    void redeemWithoutPointsIs400() throws Exception {
        mvc.perform(post("/api/loyalty/me/redeem").with(asClient1())
                        .contentType(MediaType.APPLICATION_JSON).content("{}"))
                .andExpect(status().isBadRequest())
                .andExpect(jsonPath("$.details[0].field").value("points"));
    }

    @Test
    void myVouchersListsUnusedPersonalCodes() throws Exception {
        mvc.perform(get("/api/loyalty/me/vouchers").with(asClient1()))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$[*].code").value(hasItem("LOY-DEMO40")));
        mvc.perform(get("/api/loyalty/me/vouchers").with(asClient2()))
                .andExpect(jsonPath("$.length()").value(0));
    }

    @Test
    void staffListsAccountsFilteredByTier() throws Exception {
        mvc.perform(get("/api/loyalty/accounts?tier=GOLD").with(asAgent()))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.total").value(1))
                .andExpect(jsonPath("$.items[0].userId").value(CLIENT1));
        mvc.perform(get("/api/loyalty/accounts").with(asAdmin()))
                .andExpect(jsonPath("$.total").value(2));
    }

    @Test
    void clientCannotListAccounts() throws Exception {
        mvc.perform(get("/api/loyalty/accounts").with(asClient1()))
                .andExpect(status().isForbidden());
    }

    @Test
    void accountDetailForOwnerOrStaffOnly() throws Exception {
        mvc.perform(get("/api/loyalty/accounts/" + CLIENT2).with(asClient2())).andExpect(status().isOk());
        mvc.perform(get("/api/loyalty/accounts/" + CLIENT2).with(asAgent())).andExpect(status().isOk());
        mvc.perform(get("/api/loyalty/accounts/" + CLIENT1).with(asClient2())).andExpect(status().isForbidden());
    }

    @Test
    void adminAdjustmentCanRaiseTheTier() throws Exception {
        mvc.perform(post("/api/loyalty/accounts/" + CLIENT2 + "/adjust").with(asAdmin())
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"points\":400,\"reason\":\"Geste commercial\"}"))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.balance").value(580))
                .andExpect(jsonPath("$.lifetimePoints").value(580))
                .andExpect(jsonPath("$.tier").value("SILVER"));
    }

    @Test
    void negativeAdjustmentCannotGoBelowZero() throws Exception {
        mvc.perform(post("/api/loyalty/accounts/" + CLIENT2 + "/adjust").with(asAdmin())
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"points\":-500,\"reason\":\"Correction\"}"))
                .andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.details[0].field").value("points"));
    }

    @Test
    void adjustmentNeedsAReason() throws Exception {
        mvc.perform(post("/api/loyalty/accounts/" + CLIENT2 + "/adjust").with(asAdmin())
                        .contentType(MediaType.APPLICATION_JSON).content("{\"points\":10}"))
                .andExpect(status().isBadRequest())
                .andExpect(jsonPath("$.details[0].field").value("reason"));
    }

    @Test
    void onlyAdminCanAdjust() throws Exception {
        mvc.perform(post("/api/loyalty/accounts/" + CLIENT2 + "/adjust").with(asAgent())
                        .contentType(MediaType.APPLICATION_JSON).content("{\"points\":10,\"reason\":\"x\"}"))
                .andExpect(status().isForbidden());
    }

    @Test
    void withoutTokenIs401() throws Exception {
        mvc.perform(get("/api/loyalty/me"))
                .andExpect(status().isUnauthorized())
                .andExpect(jsonPath("$.status").value(401));
    }

    @Test
    void internalRouteNeedsTheInternalRole() throws Exception {
        mvc.perform(get("/internal/loyalty/" + CLIENT1).with(asService()))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.tier").value("GOLD"))
                .andExpect(jsonPath("$.balance").value(1240))
                .andExpect(jsonPath("$.status").value("ACTIVE"));
        mvc.perform(get("/internal/loyalty/" + CLIENT1).with(asClient1()))
                .andExpect(status().isForbidden());
    }

    @Test
    void healthIsPublic() throws Exception {
        mvc.perform(get("/health")).andExpect(status().isOk()).andExpect(jsonPath("$.status").value("UP"));
    }
}
