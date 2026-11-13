package com.location.loyaltyservice.common;

import jakarta.servlet.http.HttpServletRequest;
import java.util.UUID;
import org.springframework.web.context.request.RequestContextHolder;
import org.springframework.web.context.request.ServletRequestAttributes;

/**
 * X-Correlation-Id à retransmettre tel quel (docs/conventions.md) :
 * celui de la requête HTTP en cours, sinon celui du message RabbitMQ en cours de traitement, sinon un nouveau.
 */
public final class CorrelationId {

    public static final String HEADER = "X-Correlation-Id";
    private static final ThreadLocal<String> FROM_MESSAGE = new ThreadLocal<>();

    public static String current() {
        if (RequestContextHolder.getRequestAttributes() instanceof ServletRequestAttributes attrs) {
            HttpServletRequest request = attrs.getRequest();
            String id = request.getHeader(HEADER);
            if (id != null && !id.isBlank()) {
                return id;
            }
        }
        String fromMessage = FROM_MESSAGE.get();
        return fromMessage != null ? fromMessage : UUID.randomUUID().toString();
    }

    /** Pendant le traitement d'un message : le X-Correlation-Id de ses en-têtes. */
    public static void setFromMessage(String id) {
        if (id == null || id.isBlank()) {
            FROM_MESSAGE.remove();
        } else {
            FROM_MESSAGE.set(id);
        }
    }

    public static void clear() {
        FROM_MESSAGE.remove();
    }

    private CorrelationId() {
    }
}
