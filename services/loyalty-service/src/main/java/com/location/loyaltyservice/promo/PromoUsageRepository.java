package com.location.loyaltyservice.promo;

import java.util.Optional;
import java.util.UUID;
import org.springframework.data.jpa.repository.JpaRepository;

public interface PromoUsageRepository extends JpaRepository<PromoUsage, UUID> {

    Optional<PromoUsage> findByReservationId(UUID reservationId);

    long countByPromoCodeIdAndUserId(UUID promoCodeId, UUID userId);

    boolean existsByPromoCodeId(UUID promoCodeId);
}
