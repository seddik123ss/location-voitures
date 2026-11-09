package com.location.userservice.common;

/** Références vers les réponses d'erreur communes déclarées dans {@link OpenApiConfig}. */
public final class ApiDocs {

    public static final String BAD_REQUEST = "#/components/responses/BadRequest";
    public static final String UNAUTHORIZED = "#/components/responses/Unauthorized";
    public static final String FORBIDDEN = "#/components/responses/Forbidden";
    public static final String NOT_FOUND = "#/components/responses/NotFound";
    public static final String CONFLICT = "#/components/responses/Conflict";
    public static final String UNPROCESSABLE = "#/components/responses/Unprocessable";
    public static final String BAD_GATEWAY = "#/components/responses/BadGateway";
    public static final String SERVICE_UNAVAILABLE = "#/components/responses/ServiceUnavailable";

    /** UUID Keycloak de client1 (docs/demo-ids.md), utilisé comme exemple. */
    public static final String CLIENT1_ID = "f4d1bab3-468d-428d-b02c-b44341430bfd";

    private ApiDocs() {
    }
}
