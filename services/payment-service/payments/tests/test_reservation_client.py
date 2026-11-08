import uuid
from decimal import Decimal
from unittest import mock

import requests
from django.test import SimpleTestCase, override_settings

from payments.clients.discovery import ServiceNotFound
from payments.clients.reservations import (
    FakeReservationClient,
    HttpReservationClient,
    ReservationBadResponse,
    ReservationNotFound,
    ReservationServiceUnavailable,
    get_reservation_client,
)
from payments.correlation import _current as correlation_var
from payments.demo_data import R1, USER_ALICE

RID = uuid.UUID("aaaaaaaa-0000-4000-8000-0000000000aa")
VALID_PAYLOAD = {
    "id": str(RID), "userId": str(USER_ALICE), "vehicleId": str(uuid.uuid4()),
    "startDate": "2026-12-01", "endDate": "2026-12-03", "status": "PENDING",
    "totalPrice": 300.0, "depositAmount": "800.000", "currency": "TND",
}


class FakeReservationClientTests(SimpleTestCase):
    def setUp(self):
        self.client_ = FakeReservationClient()

    def test_returns_demo_reservation(self):
        r = self.client_.get_reservation(R1)
        self.assertEqual(r.status, "CONFIRMED")
        self.assertEqual(r.total_price, Decimal("450.000"))
        self.assertEqual(r.user_id, USER_ALICE)

    def test_unknown_reservation_is_404(self):
        with self.assertRaises(ReservationNotFound) as ctx:
            self.client_.get_reservation(uuid.uuid4())
        self.assertEqual(ctx.exception.status_code, 404)

    def test_special_ids_simulate_503_and_502(self):
        with self.assertRaises(ReservationServiceUnavailable) as ctx:
            self.client_.get_reservation(FakeReservationClient.UNAVAILABLE_ID)
        self.assertEqual(ctx.exception.status_code, 503)
        with self.assertRaises(ReservationBadResponse) as ctx:
            self.client_.get_reservation(FakeReservationClient.BAD_RESPONSE_ID)
        self.assertEqual(ctx.exception.status_code, 502)


def http_response(status, json_data=None, invalid_json=False):
    response = mock.Mock(status_code=status)
    if invalid_json:
        response.json.side_effect = ValueError("not json")
    else:
        response.json.return_value = json_data
    return response


class HttpReservationClientTests(SimpleTestCase):
    def make(self, response=None, side_effect=None, resolver=None):
        session = mock.Mock(spec=requests.Session)
        session.get.return_value = response
        session.get.side_effect = side_effect
        client = HttpReservationClient(
            resolve_base_url=resolver or (lambda app: "http://10.0.0.5:8103/"),
            token_provider=lambda: "svc-token",
            session=session,
        )
        return client, session

    def test_200_is_parsed_and_headers_forwarded(self):
        client, session = self.make(http_response(200, VALID_PAYLOAD))
        token = correlation_var.set("corr-123")
        try:
            r = client.get_reservation(RID)
        finally:
            correlation_var.reset(token)
        self.assertEqual(r.id, RID)
        self.assertEqual(r.total_price, Decimal("300.0"))
        url = session.get.call_args.args[0]
        headers = session.get.call_args.kwargs["headers"]
        self.assertEqual(url, f"http://10.0.0.5:8103/internal/reservations/{RID}")
        self.assertEqual(headers["X-Correlation-Id"], "corr-123")
        self.assertEqual(headers["Authorization"], "Bearer svc-token")

    def test_remote_404_is_404(self):
        client, _ = self.make(http_response(404))
        with self.assertRaises(ReservationNotFound):
            client.get_reservation(RID)

    def test_absent_from_eureka_is_503(self):
        def resolver(app):
            raise ServiceNotFound(app)
        client, session = self.make(resolver=resolver)
        with self.assertRaises(ReservationServiceUnavailable):
            client.get_reservation(RID)
        session.get.assert_not_called()

    def test_connection_error_and_timeout_are_503(self):
        for error in (requests.ConnectionError("refused"), requests.Timeout("slow")):
            client, _ = self.make(side_effect=error)
            with self.subTest(error=type(error).__name__), self.assertRaises(ReservationServiceUnavailable):
                client.get_reservation(RID)

    def test_remote_503_is_503(self):
        client, _ = self.make(http_response(503))
        with self.assertRaises(ReservationServiceUnavailable):
            client.get_reservation(RID)

    def test_unexpected_status_is_502(self):
        client, _ = self.make(http_response(500))
        with self.assertRaises(ReservationBadResponse):
            client.get_reservation(RID)

    def test_invalid_json_is_502(self):
        client, _ = self.make(http_response(200, invalid_json=True))
        with self.assertRaises(ReservationBadResponse):
            client.get_reservation(RID)

    def test_missing_field_or_unknown_status_is_502(self):
        broken = {k: v for k, v in VALID_PAYLOAD.items() if k != "totalPrice"}
        for payload in (broken, {**VALID_PAYLOAD, "status": "WHATEVER"}, ["not", "an", "object"]):
            client, _ = self.make(http_response(200, payload))
            with self.subTest(payload=payload), self.assertRaises(ReservationBadResponse):
                client.get_reservation(RID)


class FactoryTests(SimpleTestCase):
    @override_settings(RESERVATION_CLIENT="fake")
    def test_fake(self):
        self.assertIsInstance(get_reservation_client(), FakeReservationClient)

    @override_settings(RESERVATION_CLIENT="http")
    def test_http(self):
        self.assertIsInstance(get_reservation_client(), HttpReservationClient)

    @override_settings(RESERVATION_CLIENT="other")
    def test_unknown(self):
        with self.assertRaises(ValueError):
            get_reservation_client()
