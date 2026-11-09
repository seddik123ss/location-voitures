package com.location.userservice.license;

import java.util.Optional;
import java.util.UUID;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.Pageable;
import org.springframework.data.jpa.repository.JpaRepository;

public interface DriverLicenseRepository extends JpaRepository<DriverLicense, UUID> {

    Optional<DriverLicense> findByUserId(UUID userId);

    boolean existsByUserId(UUID userId);

    boolean existsByNumberIgnoreCase(String number);

    boolean existsByNumberIgnoreCaseAndIdNot(String number, UUID id);

    Page<DriverLicense> findByUserId(UUID userId, Pageable pageable);
}
