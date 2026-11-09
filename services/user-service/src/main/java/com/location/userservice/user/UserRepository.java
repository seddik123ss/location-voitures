package com.location.userservice.user;

import java.util.UUID;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.Pageable;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

public interface UserRepository extends JpaRepository<User, UUID> {

    boolean existsByEmailIgnoreCase(String email);

    boolean existsByEmailIgnoreCaseAndIdNot(String email, UUID id);

    /**
     * Filtre optionnel par rôle et par nom (prénom, nom ou "prénom nom", sans tenir compte de la casse).
     * pattern = null pour ne pas filtrer, sinon "%texte%" en minuscules.
     */
    @Query("""
            select u from User u
            where (:role is null or u.role = :role)
              and (:pattern is null
                   or lower(u.firstname) like :pattern
                   or lower(u.lastname) like :pattern
                   or lower(concat(u.firstname, ' ', u.lastname)) like :pattern)
            """)
    Page<User> search(@Param("role") Role role, @Param("pattern") String pattern, Pageable pageable);
}
