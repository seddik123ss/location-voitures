package com.location.loyaltyservice.common;

import io.swagger.v3.oas.annotations.media.Schema;
import java.util.List;
import java.util.function.Function;
import org.springframework.data.domain.Page;

/** Liste paginée commune : {"items":[...],"page":0,"size":20,"total":57}. */
@Schema(description = "Liste paginée")
public record PageResponse<T>(
        List<T> items,
        @Schema(example = "0") int page,
        @Schema(example = "20") int size,
        @Schema(example = "57") long total) {

    public static <E, T> PageResponse<T> from(Page<E> page, Function<E, T> mapper) {
        return new PageResponse<>(page.getContent().stream().map(mapper).toList(),
                page.getNumber(), page.getSize(), page.getTotalElements());
    }
}
