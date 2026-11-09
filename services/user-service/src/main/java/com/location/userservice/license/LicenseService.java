package com.location.userservice.license;

import com.location.userservice.common.ApiException;
import com.location.userservice.common.PageResponse;
import com.location.userservice.license.dto.CreateLicenseRequest;
import com.location.userservice.license.dto.LicenseResponse;
import com.location.userservice.license.dto.UpdateLicenseRequest;
import com.location.userservice.user.UserService;
import java.time.Clock;
import java.time.LocalDate;
import java.util.Locale;
import java.util.UUID;
import org.springframework.data.domain.PageRequest;
import org.springframework.data.domain.Sort;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

/**
 * Règles métier des permis :
 * <ul>
 *   <li>numéro unique -> 409 ; un seul permis par utilisateur -> 409</li>
 *   <li>permis déjà expiré à la création -> 422</li>
 *   <li>date de délivrance dans le futur ou expiration avant délivrance -> 422</li>
 *   <li>vérification (AGENT/ADMIN) impossible sur un permis expiré -> 422</li>
 *   <li>toute modification du permis annule sa vérification</li>
 * </ul>
 */
@Service
@Transactional(readOnly = true)
public class LicenseService {

    private final DriverLicenseRepository licenses;
    private final UserService users;
    private final Clock clock;

    public LicenseService(DriverLicenseRepository licenses, UserService users, Clock clock) {
        this.licenses = licenses;
        this.users = users;
        this.clock = clock;
    }

    public PageResponse<LicenseResponse> list(UUID userId, int page, int size) {
        PageRequest pageable = PageRequest.of(page, size, Sort.by("expiryDate", "id"));
        LocalDate today = today();
        var result = userId == null ? licenses.findAll(pageable) : licenses.findByUserId(userId, pageable);
        return PageResponse.from(result, l -> LicenseResponse.from(l, today));
    }

    public LicenseResponse get(UUID id) {
        return LicenseResponse.from(require(id), today());
    }

    @Transactional
    public LicenseResponse create(CreateLicenseRequest request) {
        users.require(request.userId());
        LocalDate today = today();
        checkDates(request.issueDate(), request.expiryDate(), today);
        if (request.expiryDate().isBefore(today)) {
            throw ApiException.unprocessable("Le permis est déjà expiré (expiration le " + request.expiryDate() + ")",
                    "expiryDate");
        }
        if (licenses.existsByUserId(request.userId())) {
            throw ApiException.conflict("Cet utilisateur a déjà un permis", "userId");
        }
        String number = normalizeNumber(request.number());
        if (licenses.existsByNumberIgnoreCase(number)) {
            throw ApiException.conflict("Ce numéro de permis est déjà enregistré", "number");
        }
        DriverLicense license = new DriverLicense(UUID.randomUUID(), request.userId());
        license.setNumber(number);
        license.setCategory(request.category());
        license.setIssueDate(request.issueDate());
        license.setExpiryDate(request.expiryDate());
        license.setVerified(false);
        return LicenseResponse.from(licenses.save(license), today);
    }

    @Transactional
    public LicenseResponse update(UUID id, UpdateLicenseRequest request) {
        DriverLicense license = require(id);
        LocalDate today = today();
        checkDates(request.issueDate(), request.expiryDate(), today);
        String number = normalizeNumber(request.number());
        if (licenses.existsByNumberIgnoreCaseAndIdNot(number, id)) {
            throw ApiException.conflict("Ce numéro de permis est déjà enregistré", "number");
        }
        boolean changed = !number.equals(license.getNumber())
                || !request.category().equals(license.getCategory())
                || !request.issueDate().equals(license.getIssueDate())
                || !request.expiryDate().equals(license.getExpiryDate());
        license.setNumber(number);
        license.setCategory(request.category());
        license.setIssueDate(request.issueDate());
        license.setExpiryDate(request.expiryDate());
        if (changed) {
            license.setVerified(false);
        }
        return LicenseResponse.from(licenses.save(license), today);
    }

    /** Un AGENT ou un ADMIN confirme avoir contrôlé le permis. */
    @Transactional
    public LicenseResponse verify(UUID id) {
        DriverLicense license = require(id);
        LocalDate today = today();
        if (license.isExpiredOn(today)) {
            throw ApiException.unprocessable("Impossible de vérifier un permis expiré", "expiryDate");
        }
        license.setVerified(true);
        return LicenseResponse.from(licenses.save(license), today);
    }

    @Transactional
    public void delete(UUID id) {
        licenses.delete(require(id));
    }

    private DriverLicense require(UUID id) {
        return licenses.findById(id)
                .orElseThrow(() -> ApiException.notFound("Permis introuvable : " + id));
    }

    private static void checkDates(LocalDate issueDate, LocalDate expiryDate, LocalDate today) {
        if (issueDate.isAfter(today)) {
            throw ApiException.unprocessable("La date de délivrance ne peut pas être dans le futur", "issueDate");
        }
        if (!expiryDate.isAfter(issueDate)) {
            throw ApiException.unprocessable("La date d'expiration doit être après la date de délivrance", "expiryDate");
        }
    }

    private LocalDate today() {
        return LocalDate.now(clock);
    }

    private static String normalizeNumber(String number) {
        return number.trim().toUpperCase(Locale.ROOT);
    }
}
