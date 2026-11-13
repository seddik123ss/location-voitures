package com.location.loyaltyservice;

import com.location.loyaltyservice.remote.ReservationClient;
import com.location.loyaltyservice.remote.UserClient;
import java.time.Clock;
import java.time.Instant;
import java.time.ZoneOffset;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.boot.test.context.TestConfiguration;
import org.springframework.boot.test.mock.mockito.MockBean;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Import;
import org.springframework.context.annotation.Primary;
import org.springframework.test.context.ActiveProfiles;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.transaction.annotation.Transactional;

/**
 * Base des tests d'intégration : H2 (mêmes migrations Flyway que MySQL), données de démo V2,
 * horloge figée au 2026-11-20, clients OpenFeign simulés. Chaque test est annulé à la fin (@Transactional).
 */
@SpringBootTest
@AutoConfigureMockMvc
@ActiveProfiles("test")
@Import(IntegrationTestBase.FixedClock.class)
@Transactional
public abstract class IntegrationTestBase {

    public static final Instant NOW = Instant.parse("2026-11-20T10:00:00Z");

    @TestConfiguration
    static class FixedClock {
        @Bean
        @Primary
        Clock fixedClock() {
            return Clock.fixed(NOW, ZoneOffset.UTC);
        }
    }

    @Autowired
    protected MockMvc mvc;

    @MockBean
    protected UserClient userClient;

    @MockBean
    protected ReservationClient reservationClient;
}
