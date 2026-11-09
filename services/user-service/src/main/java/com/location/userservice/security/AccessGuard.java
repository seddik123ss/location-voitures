package com.location.userservice.security;

import com.location.userservice.license.DriverLicenseRepository;
import java.util.Arrays;
import java.util.Optional;
import java.util.UUID;
import org.springframework.security.core.Authentication;
import org.springframework.security.core.GrantedAuthority;
import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.stereotype.Component;

/**
 * Règles d'accès utilisées dans les @PreAuthorize, par exemple
 * {@code @PreAuthorize("hasRole('ADMIN') or @access.isSelf(#id)")}.
 */
@Component("access")
public class AccessGuard {

    private final DriverLicenseRepository licenses;

    public AccessGuard(DriverLicenseRepository licenses) {
        this.licenses = licenses;
    }

    /** L'utilisateur connecté est-il celui dont l'id est donné ? (id = claim "sub" de Keycloak) */
    public boolean isSelf(UUID userId) {
        return userId != null && currentUserId().map(userId::equals).orElse(false);
    }

    public boolean isAdmin() {
        return hasAnyRole("ADMIN");
    }

    public boolean isStaff() {
        return hasAnyRole("ADMIN", "AGENT");
    }

    /** Lecture d'un permis : ADMIN, AGENT ou propriétaire. Permis inexistant : on laisse passer pour renvoyer 404. */
    public boolean canReadLicense(UUID licenseId) {
        return isStaff() || ownsLicenseOrMissing(licenseId);
    }

    /** Modification / suppression d'un permis : ADMIN ou propriétaire. */
    public boolean canManageLicense(UUID licenseId) {
        return isAdmin() || ownsLicenseOrMissing(licenseId);
    }

    public Optional<UUID> currentUserId() {
        Authentication auth = SecurityContextHolder.getContext().getAuthentication();
        if (auth == null || auth.getName() == null) {
            return Optional.empty();
        }
        try {
            return Optional.of(UUID.fromString(auth.getName()));
        } catch (IllegalArgumentException e) {
            return Optional.empty();
        }
    }

    private boolean ownsLicenseOrMissing(UUID licenseId) {
        return licenses.findById(licenseId)
                .map(l -> isSelf(l.getUserId()))
                .orElse(true);
    }

    private boolean hasAnyRole(String... roles) {
        Authentication auth = SecurityContextHolder.getContext().getAuthentication();
        if (auth == null) {
            return false;
        }
        return auth.getAuthorities().stream()
                .map(GrantedAuthority::getAuthority)
                .anyMatch(a -> Arrays.stream(roles).anyMatch(r -> a.equals("ROLE_" + r)));
    }
}
