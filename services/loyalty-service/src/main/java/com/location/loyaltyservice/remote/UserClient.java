package com.location.loyaltyservice.remote;

import java.util.UUID;
import org.springframework.cloud.openfeign.FeignClient;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;

/**
 * Client OpenFeign vers user-service (Montassar). name = nom Eureka : l'adresse est trouvée dans Eureka.
 * Contrat : docs/internal-api.md, GET /internal/users/{id}.
 */
@FeignClient(name = "RENTAL-USER", configuration = RemoteFeignConfig.class)
public interface UserClient {

    @GetMapping("/internal/users/{id}")
    RemoteUser getUser(@PathVariable("id") UUID id);
}
