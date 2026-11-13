package com.location.loyaltyservice.remote;

import java.util.UUID;

/** Champs lus dans la réponse de GET /internal/users/{id} (les autres sont ignorés). */
public record RemoteUser(UUID id, String role, Boolean active) {
}
