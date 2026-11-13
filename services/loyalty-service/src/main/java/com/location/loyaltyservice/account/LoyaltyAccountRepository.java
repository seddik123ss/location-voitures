package com.location.loyaltyservice.account;

import jakarta.persistence.LockModeType;
import java.util.Optional;
import java.util.UUID;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.Pageable;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

public interface LoyaltyAccountRepository extends JpaRepository<LoyaltyAccount, UUID> {

    Page<LoyaltyAccount> findByTier(Tier tier, Pageable pageable);

    /** Lecture avec verrou (SELECT ... FOR UPDATE) : deux conversions simultanées ne dépensent pas deux fois le solde. */
    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("select a from LoyaltyAccount a where a.userId = :userId")
    Optional<LoyaltyAccount> findForUpdate(@Param("userId") UUID userId);
}
