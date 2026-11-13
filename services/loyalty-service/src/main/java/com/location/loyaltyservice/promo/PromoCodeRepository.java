package com.location.loyaltyservice.promo;

import jakarta.persistence.LockModeType;
import java.util.List;
import java.util.Optional;
import java.util.UUID;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.Pageable;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

public interface PromoCodeRepository extends JpaRepository<PromoCode, UUID> {

    Optional<PromoCode> findByCode(String code);

    boolean existsByCode(String code);

    /** Lecture avec verrou pour /apply : deux réservations simultanées ne dépassent pas maxUses. */
    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("select p from PromoCode p where p.code = :code")
    Optional<PromoCode> findByCodeForUpdate(@Param("code") String code);

    /** Codes promo de l'ADMIN (sans les bons personnels), filtrés ou non sur "active". */
    @Query("select p from PromoCode p where p.ownerUserId is null and (:active is null or p.active = :active)")
    Page<PromoCode> findPublicCodes(@Param("active") Boolean active, Pageable pageable);

    List<PromoCode> findByOwnerUserIdOrderByCreatedAtDesc(UUID ownerUserId);
}
