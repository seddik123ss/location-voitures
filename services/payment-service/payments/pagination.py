"""
Common pagination: ?page=0&size=20 -> {"items": [...], "page": 0, "size": 20, "total": 57}
Pages are 0-based. Invalid page/size -> 400 in the common error format.
"""
from rest_framework.exceptions import ValidationError
from rest_framework.pagination import BasePagination
from rest_framework.response import Response


class PageSizePagination(BasePagination):
    page_query_param = "page"
    size_query_param = "size"
    default_size = 20
    max_size = 100

    def _read_int(self, request, name, default, minimum, maximum=None):
        raw = request.query_params.get(name)
        if raw in (None, ""):
            return default
        try:
            value = int(raw)
        except ValueError:
            raise ValidationError({name: [f"Doit être un entier (reçu : {raw!r})."]})
        if value < minimum or (maximum is not None and value > maximum):
            bounds = f"entre {minimum} et {maximum}" if maximum is not None else f">= {minimum}"
            raise ValidationError({name: [f"Doit être {bounds}."]})
        return value

    def paginate_queryset(self, queryset, request, view=None):
        self.page = self._read_int(request, self.page_query_param, 0, 0)
        self.size = self._read_int(request, self.size_query_param, self.default_size, 1, self.max_size)
        # QuerySet.count() runs SELECT COUNT(*); plain lists (tests) use len().
        self.total = queryset.count() if hasattr(queryset, "query") else len(queryset)
        start = self.page * self.size
        return list(queryset[start:start + self.size])

    def get_paginated_response(self, data):
        return Response({"items": data, "page": self.page, "size": self.size, "total": self.total})

    # --- OpenAPI (drf-spectacular) ---
    def get_paginated_response_schema(self, schema):
        return {
            "type": "object",
            "required": ["items", "page", "size", "total"],
            "properties": {
                "items": schema,
                "page": {"type": "integer", "example": 0},
                "size": {"type": "integer", "example": 20},
                "total": {"type": "integer", "example": 57},
            },
        }

    def get_schema_operation_parameters(self, view):
        return [
            {"name": self.page_query_param, "in": "query", "required": False,
             "description": "Numéro de page (commence à 0).",
             "schema": {"type": "integer", "minimum": 0, "default": 0}},
            {"name": self.size_query_param, "in": "query", "required": False,
             "description": f"Taille de page (1 à {self.max_size}).",
             "schema": {"type": "integer", "minimum": 1, "maximum": self.max_size, "default": self.default_size}},
        ]
