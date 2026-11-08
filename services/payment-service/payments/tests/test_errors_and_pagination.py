from django.test import SimpleTestCase
from rest_framework import serializers
from rest_framework.exceptions import NotAuthenticated, ValidationError
from rest_framework.request import Request
from rest_framework.test import APIRequestFactory
from rest_framework.views import APIView

from payments.exceptions import ConflictError, api_exception_handler, flatten_validation_errors
from payments.pagination import PageSizePagination

factory = APIRequestFactory()


class _LineSerializer(serializers.Serializer):
    unit_price = serializers.DecimalField(max_digits=12, decimal_places=3)


class _BodySerializer(serializers.Serializer):
    reservation_id = serializers.UUIDField()
    lines = _LineSerializer(many=True)


class _View(APIView):
    authentication_classes = []
    permission_classes = []
    to_raise = None

    def post(self, request):
        if self.to_raise:
            raise self.to_raise
        _BodySerializer(data=request.data).is_valid(raise_exception=True)


class ErrorFormatTests(SimpleTestCase):
    def call(self, body=None, to_raise=None):
        view = _View.as_view(to_raise=to_raise)
        return view(factory.post("/x", body or {}, format="json"))

    def test_validation_error_is_400_with_camel_case_fields(self):
        response = self.call({"reservation_id": "nope", "lines": [{"unit_price": "abc"}]})
        self.assertEqual(response.status_code, 400)
        self.assertEqual(response.data["error"], "BAD_REQUEST")
        fields = {d["field"] for d in response.data["details"]}
        self.assertEqual(fields, {"reservationId", "lines[0].unitPrice"})

    def test_business_error_keeps_status_and_message(self):
        response = self.call(to_raise=ConflictError("Réservation déjà payée"))
        self.assertEqual(response.status_code, 409)
        self.assertEqual(response.data, {
            "status": 409, "error": "CONFLICT", "message": "Réservation déjà payée", "details": [],
        })

    def test_drf_exception_is_reformatted(self):
        response = api_exception_handler(NotAuthenticated(), {})
        self.assertEqual(response.status_code, 401)
        self.assertEqual(response.data["error"], "UNAUTHORIZED")
        self.assertIn("message", response.data)

    def test_non_field_errors_have_null_field(self):
        details = flatten_validation_errors(ValidationError({"non_field_errors": ["boom"]}).detail)
        self.assertEqual(details, [{"field": None, "message": "boom"}])

    def test_unknown_url_returns_json_404(self):
        response = self.client.get("/does-not-exist")
        self.assertEqual(response.status_code, 404)
        self.assertEqual(response.json()["error"], "NOT_FOUND")


class HealthAndCorrelationTests(SimpleTestCase):
    def test_health(self):
        response = self.client.get("/health")
        self.assertEqual(response.json(), {"status": "UP"})

    def test_correlation_id_is_echoed_or_generated(self):
        response = self.client.get("/health", headers={"X-Correlation-Id": "abc-1"})
        self.assertEqual(response["X-Correlation-Id"], "abc-1")
        generated = self.client.get("/health")["X-Correlation-Id"]
        self.assertEqual(len(generated), 36)


class PaginationTests(SimpleTestCase):
    def paginate(self, query, data=range(57)):
        paginator = PageSizePagination()
        request = Request(factory.get("/x", query))
        page = paginator.paginate_queryset(list(data), request)
        return paginator.get_paginated_response(page).data

    def test_defaults(self):
        body = self.paginate({})
        self.assertEqual((body["page"], body["size"], body["total"]), (0, 20, 57))
        self.assertEqual(body["items"][0], 0)

    def test_last_page_is_partial_and_zero_based(self):
        body = self.paginate({"page": 2, "size": 20})
        self.assertEqual(body["items"], list(range(40, 57)))

    def test_invalid_values_are_400(self):
        for query in ({"page": -1}, {"size": 0}, {"size": 101}, {"page": "x"}):
            with self.subTest(query=query), self.assertRaises(ValidationError):
                self.paginate(query)
