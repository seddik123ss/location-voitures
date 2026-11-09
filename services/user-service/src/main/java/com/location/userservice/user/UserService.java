package com.location.userservice.user;

import com.location.userservice.address.AddressRepository;
import com.location.userservice.address.dto.AddressResponse;
import com.location.userservice.common.ApiException;
import com.location.userservice.common.PageResponse;
import com.location.userservice.license.DriverLicenseRepository;
import com.location.userservice.license.dto.LicenseResponse;
import com.location.userservice.user.dto.CreateUserRequest;
import com.location.userservice.user.dto.InternalUserResponse;
import com.location.userservice.user.dto.UpdateUserRequest;
import com.location.userservice.user.dto.UserResponse;
import com.location.userservice.user.dto.UserSummaryResponse;
import java.time.Clock;
import java.time.LocalDate;
import java.util.List;
import java.util.Locale;
import java.util.UUID;
import org.springframework.data.domain.PageRequest;
import org.springframework.data.domain.Sort;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

/**
 * Règles métier des utilisateurs :
 * email unique (409), email/téléphone au bon format (422, via la validation des DTO),
 * seul un ADMIN change le rôle ou le statut actif (403).
 */
@Service
@Transactional(readOnly = true)
public class UserService {

    private final UserRepository users;
    private final AddressRepository addresses;
    private final DriverLicenseRepository licenses;
    private final Clock clock;

    public UserService(UserRepository users, AddressRepository addresses,
                       DriverLicenseRepository licenses, Clock clock) {
        this.users = users;
        this.addresses = addresses;
        this.licenses = licenses;
        this.clock = clock;
    }

    public PageResponse<UserSummaryResponse> search(Role role, String name, int page, int size) {
        String pattern = (name == null || name.isBlank()) ? null : "%" + name.trim().toLowerCase(Locale.ROOT) + "%";
        PageRequest pageable = PageRequest.of(page, size, Sort.by("lastname", "firstname", "id"));
        return PageResponse.from(users.search(role, pattern, pageable), UserSummaryResponse::from);
    }

    public UserResponse get(UUID id) {
        return toResponse(require(id));
    }

    public InternalUserResponse getInternal(UUID id) {
        User user = require(id);
        return InternalUserResponse.from(user, licenses.findByUserId(id).orElse(null));
    }

    @Transactional
    public UserResponse create(CreateUserRequest request, boolean asAdmin) {
        boolean active = request.active() == null || request.active();
        if (!asAdmin && (request.role() != Role.CLIENT || !active)) {
            throw ApiException.forbidden("Sans le rôle ADMIN, on ne peut créer que son propre profil, actif, avec le rôle CLIENT");
        }
        if (users.existsById(request.id())) {
            throw ApiException.conflict("Un utilisateur existe déjà avec cet identifiant", "id");
        }
        String email = normalizeEmail(request.email());
        if (users.existsByEmailIgnoreCase(email)) {
            throw ApiException.conflict("Cet email est déjà utilisé", "email");
        }
        User user = new User(request.id());
        user.setFirstname(request.firstname().trim());
        user.setLastname(request.lastname().trim());
        user.setEmail(email);
        user.setPhone(normalizePhone(request.phone()));
        user.setRole(request.role());
        user.setActive(active);
        return toResponse(users.save(user));
    }

    @Transactional
    public UserResponse update(UUID id, UpdateUserRequest request, boolean asAdmin) {
        User user = require(id);
        if (!asAdmin && (request.role() != user.getRole() || request.active() != user.isActive())) {
            throw ApiException.forbidden("Seul un ADMIN peut modifier le rôle ou le statut actif");
        }
        String email = normalizeEmail(request.email());
        if (users.existsByEmailIgnoreCaseAndIdNot(email, id)) {
            throw ApiException.conflict("Cet email est déjà utilisé", "email");
        }
        user.setFirstname(request.firstname().trim());
        user.setLastname(request.lastname().trim());
        user.setEmail(email);
        user.setPhone(normalizePhone(request.phone()));
        user.setRole(request.role());
        user.setActive(request.active());
        return toResponse(users.saveAndFlush(user));
    }

    /** Supprime l'utilisateur ; ses adresses et son permis sont supprimés par la base (ON DELETE CASCADE). */
    @Transactional
    public void delete(UUID id) {
        users.delete(require(id));
    }

    /** 404 si l'utilisateur n'existe pas. */
    public User require(UUID id) {
        return users.findById(id)
                .orElseThrow(() -> ApiException.notFound("Utilisateur introuvable : " + id));
    }

    private UserResponse toResponse(User user) {
        LocalDate today = LocalDate.now(clock);
        LicenseResponse license = licenses.findByUserId(user.getId())
                .map(l -> LicenseResponse.from(l, today))
                .orElse(null);
        List<AddressResponse> userAddresses = addresses.findByUserIdOrderByCityAscStreetAsc(user.getId()).stream()
                .map(AddressResponse::from)
                .toList();
        return UserResponse.from(user, license, userAddresses);
    }

    private static String normalizeEmail(String email) {
        return email.trim().toLowerCase(Locale.ROOT);
    }

    private static String normalizePhone(String phone) {
        return (phone == null || phone.isBlank()) ? null : phone.trim();
    }
}
