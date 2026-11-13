package com.location.loyaltyservice;

import static org.assertj.core.api.Assertions.assertThat;

import com.location.loyaltyservice.account.LoyaltyAccount;
import com.location.loyaltyservice.account.LoyaltyService;
import com.location.loyaltyservice.account.Tier;
import com.location.loyaltyservice.promo.Discount;
import com.location.loyaltyservice.promo.DiscountType;
import java.math.BigDecimal;
import java.time.Instant;
import java.util.UUID;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.CsvSource;

/** Règles de calcul (section 4) : tests unitaires, sans Spring. */
class CalculationRulesTest {

    private static final Instant NOW = Instant.parse("2026-11-20T10:00:00Z");

    @ParameterizedTest(name = "{0} TND en {1} -> {2} points")
    @CsvSource({
            "640.000, BRONZE, 64",
            "640.000, SILVER, 80",     // exemple du cahier des charges : 64 x 1.25
            "640.000, GOLD, 96",
            "99.999, BRONZE, 9",       // floor(9.9999) = 9
            "95.000, GOLD, 13",        // 9 x 1.5 = 13.5 -> 13
            "9.000, GOLD, 0"
    })
    void pointsDependOnTierAndAreRoundedDown(BigDecimal totalPrice, Tier tier, int expected) {
        assertThat(LoyaltyService.pointsFor(totalPrice, tier)).isEqualTo(expected);
    }

    @ParameterizedTest(name = "cumul {0} -> {1}")
    @CsvSource({"0, BRONZE", "499, BRONZE", "500, SILVER", "1499, SILVER", "1500, GOLD", "9000, GOLD"})
    void tierThresholds(int lifetimePoints, Tier expected) {
        assertThat(Tier.forLifetimePoints(lifetimePoints)).isEqualTo(expected);
    }

    @Test
    void accountGoesFromBronzeToSilverToGold() {
        LoyaltyAccount account = LoyaltyAccount.open(UUID.randomUUID(), NOW);
        account.earn(100, NOW);
        assertThat(account.getTier()).isEqualTo(Tier.BRONZE);
        account.earn(400, NOW);
        assertThat(account.getTier()).isEqualTo(Tier.SILVER);
        account.earn(1000, NOW);
        assertThat(account.getTier()).isEqualTo(Tier.GOLD);
        assertThat(account.getLifetimePoints()).isEqualTo(1500);
    }

    @Test
    void spendingPointsNeverLowersTheTier() {
        LoyaltyAccount account = LoyaltyAccount.open(UUID.randomUUID(), NOW);
        account.earn(1600, NOW);
        account.spend(1500, NOW);
        assertThat(account.getBalance()).isEqualTo(100);
        assertThat(account.getLifetimePoints()).isEqualTo(1600);
        assertThat(account.getTier()).isEqualTo(Tier.GOLD);
    }

    @Test
    void percentDiscount() {
        Discount d = Discount.compute(DiscountType.PERCENT, new BigDecimal("10"), new BigDecimal("450"));
        assertThat(d.discount()).isEqualByComparingTo("45.000");
        assertThat(d.amountAfter()).isEqualByComparingTo("405.000");
    }

    @Test
    void discountNeverExceedsTheAmount() {
        Discount d = Discount.compute(DiscountType.AMOUNT, new BigDecimal("50.000"), new BigDecimal("30.000"));
        assertThat(d.discount()).isEqualByComparingTo("30.000");
        assertThat(d.amountAfter()).isEqualByComparingTo("0.000");
    }

    @Test
    void discountIsRoundedToThreeDecimals() {
        Discount d = Discount.compute(DiscountType.PERCENT, new BigDecimal("15"), new BigDecimal("123.457"));
        assertThat(d.discount()).isEqualTo(new BigDecimal("18.519"));   // 18.51855 -> 18.519
        assertThat(d.amountAfter()).isEqualTo(new BigDecimal("104.938"));
    }
}
