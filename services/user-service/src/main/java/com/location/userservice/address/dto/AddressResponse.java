package com.location.userservice.address.dto;

import com.location.userservice.address.Address;
import io.swagger.v3.oas.annotations.media.Schema;
import java.util.UUID;

@Schema(description = "Adresse")
public record AddressResponse(
        @Schema(example = "fa1bace4-042a-4ce2-be5f-d4c3cb4b98da") UUID id,
        @Schema(example = "12 Rue de Marseille") String street,
        @Schema(example = "Tunis") String city,
        @Schema(example = "1000") String zipCode,
        @Schema(example = "f4d1bab3-468d-428d-b02c-b44341430bfd") UUID userId) {

    public static AddressResponse from(Address a) {
        return new AddressResponse(a.getId(), a.getStreet(), a.getCity(), a.getZipCode(), a.getUserId());
    }
}
