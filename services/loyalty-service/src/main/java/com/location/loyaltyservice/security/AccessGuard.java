package com.location.loyaltyservice.security;

import com.location.loyaltyservice.common.ApiException;
import java.util.Arrays;
import java.util.Optional;
import java.util.UUID;
import org.springframework.security.core.Authentication;
import org.springframework.security.core.GrantedAuthority;
import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.stereotype.Component;

/**
 * Règles d'accès utilisées dans les @PreAuthorize, par exemple
 * {@code @PreAuthorize("hasAnyRole('AGENT','ADMIN') or @access.isSelf(#userId)")}.
 */
@Component("access")
public class AccessGuard {

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

    /** Id de l'utilisateur connecté, ou 403 si le token n'en porte pas (claim "sub" absente ou non UUID). */
    public UUID requireUserId() {
        return currentUserId().orElseThrow(() -> ApiException.forbidden("Token sans identifiant d'utilisateur"));
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
