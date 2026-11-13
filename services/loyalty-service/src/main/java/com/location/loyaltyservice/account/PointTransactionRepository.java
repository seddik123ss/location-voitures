package com.location.loyaltyservice.account;

import java.util.UUID;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.Pageable;
import org.springframework.data.jpa.repository.JpaRepository;

public interface PointTransactionRepository extends JpaRepository<PointTransaction, UUID> {

    Page<PointTransaction> findByUserIdOrderByCreatedAtDesc(UUID userId, Pageable pageable);

    boolean existsByTypeAndReservationId(TransactionType type, UUID reservationId);
}
