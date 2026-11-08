"""
API tests on a real PostgreSQL test database (test_payment_db), with the simulated
reservation client (docs/demo-ids.md) and a recording event publisher.
"""
import uuid
from decimal import Decimal
from io import StringIO

from django.core.management import call_command
from django.test import TestCase

from payments import events
from payments.clients.reservations import FakeReservationClient
from payments.demo_data import (
    CLIENT1,
    P_COMPLETED,
    P_CONFIRMED,
    R_COMPLETED,
    R_CONFIRMED,
    R_PENDING,
    R_TEST_CANCELLED,
)
from payments.models import Invoice, Payment

from .helpers import INTERNAL, RecordingPublisher, admin, agent, client1, client2


class ApiTestCase(TestCase):
    def setUp(self):
        call_command("seed_demo", stdout=StringIO())
        self.publisher = RecordingPublisher()
        events.set_event_publisher(self.publisher)

    def tearDown(self):
        events.set_event_publisher(None)

    def pay(self, headers, **body):
        return self.client.post("/api/payments", body, content_type="application/json", headers=headers)

    def rental_r_pending(self, headers=None, **overrides):
        body = {"reservationId": str(R_PENDING), "type": "RENTAL", "method": "CARD",
                "amount": 270.0, "cardToken": "tok_visa", **overrides}
        body = {k: v for k, v in body.items() if v is not None}  # None = field omitted
        return self.pay(headers or client1(), **body)


class CreatePaymentTests(ApiTestCase):
    def test_card_payment_succeeds_creates_invoice_and_publishes_event(self):
        response = self.rental_r_pending()
        self.assertEqual(response.status_code, 201, response.content)
        body = response.json()
        self.assertEqual(response["Location"], f"/api/payments/{body['id']}")
        self.assertEqual((body["status"], body["amount"], body["userId"]), ("SUCCEEDED", 270.0, str(CLIENT1)))
        self.assertTrue(body["transactionRef"].startswith("SIM-"))
        self.assertNotIn("cardToken", body)

        invoice = Invoice.objects.get(reservation_id=R_PENDING)
        self.assertRegex(invoice.number, r"^INV-\d{4}-\d{6}$")
        self.assertEqual(invoice.total, Decimal("270.000"))
        self.assertEqual(invoice.lines.get().label, "Location du 2026-12-10 au 2026-12-13")

        [(queue, event)] = self.publisher.published
        self.assertEqual(queue, "reservation.payment_succeeded")
        self.assertEqual(event["type"], "PaymentSucceeded")
        self.assertEqual(set(event), {"eventId", "type", "occurredAt", "data"})
        self.assertEqual(event["data"]["paymentId"], body["id"])
        self.assertEqual(event["data"]["type"], "RENTAL")

    def test_card_token_is_never_stored(self):
        self.rental_r_pending(cardToken="tok_secret_4242")
        stored = list(Payment.objects.values_list("transaction_ref", "failure_reason", "idempotency_key"))
        self.assertNotIn("tok_secret_4242", str(stored))

    def test_refused_card_is_201_failed_without_invoice_or_event(self):
        response = self.rental_r_pending(cardToken="fail_insufficient_funds")
        self.assertEqual(response.status_code, 201)
        self.assertEqual(response.json()["status"], "FAILED")
        self.assertIsNotNone(response.json()["failureReason"])
        self.assertFalse(Invoice.objects.filter(reservation_id=R_PENDING).exists())
        self.assertEqual(self.publisher.published, [])
        # A failed attempt does not block a new attempt.
        self.assertEqual(self.rental_r_pending().status_code, 201)

    def test_deposit_does_not_create_invoice_but_publishes_event(self):
        response = self.pay(client1(), reservationId=str(R_PENDING), type="DEPOSIT", method="CARD",
                            amount=500, cardToken="tok")
        self.assertEqual(response.status_code, 201)
        self.assertFalse(Invoice.objects.filter(reservation_id=R_PENDING).exists())
        self.assertEqual(self.publisher.published[0][1]["data"]["type"], "DEPOSIT")


class IdempotencyTests(ApiTestCase):
    def test_missing_key_is_400(self):
        response = self.rental_r_pending(headers=client1(idempotency_key=False))
        self.assertEqual(response.status_code, 400)
        self.assertEqual(response.json()["details"][0]["field"], "Idempotency-Key")

    def test_reused_key_is_409_even_for_identical_request(self):
        key = str(uuid.uuid4())
        self.assertEqual(self.rental_r_pending(headers=client1(idempotency_key=key)).status_code, 201)
        response = self.rental_r_pending(headers=client1(idempotency_key=key))
        self.assertEqual(response.status_code, 409)
        self.assertEqual(Payment.objects.filter(reservation_id=R_PENDING).count(), 1)

    def test_key_of_failed_card_payment_is_consumed(self):
        key = str(uuid.uuid4())
        self.rental_r_pending(headers=client1(idempotency_key=key), cardToken="fail_x")
        self.assertEqual(self.rental_r_pending(headers=client1(idempotency_key=key)).status_code, 409)


class TransactionTests(ApiTestCase):
    def test_rabbitmq_down_is_503_and_nothing_is_saved(self):
        self.publisher.fail = True
        key = str(uuid.uuid4())
        response = self.rental_r_pending(headers=client1(idempotency_key=key))
        self.assertEqual(response.status_code, 503)
        self.assertEqual(response.json()["error"], "SERVICE_UNAVAILABLE")
        self.assertFalse(Payment.objects.filter(reservation_id=R_PENDING).exists())
        self.assertFalse(Invoice.objects.filter(reservation_id=R_PENDING).exists())
        # Rollback also released the Idempotency-Key: the client retries with the same key.
        self.publisher.fail = False
        self.assertEqual(self.rental_r_pending(headers=client1(idempotency_key=key)).status_code, 201)

    def test_invoice_number_not_burned_by_rollback(self):
        before = Invoice.objects.order_by("-number").first().number
        self.publisher.fail = True
        self.rental_r_pending()
        self.publisher.fail = False
        self.rental_r_pending()
        new = Invoice.objects.get(reservation_id=R_PENDING).number
        self.assertEqual(int(new[-6:]), int(before[-6:]) + 1)


class BusinessRuleTests(ApiTestCase):
    def test_already_paid_is_409(self):
        response = self.pay(client1(), reservationId=str(R_CONFIRMED), type="RENTAL", method="CARD",
                            amount=450, cardToken="tok")
        self.assertEqual(response.status_code, 409)

    def test_cancelled_or_completed_reservation_is_409(self):
        for rid, owner in ((R_TEST_CANCELLED, client2()), (R_COMPLETED, client2())):
            response = self.pay(owner, reservationId=str(rid), type="DEPOSIT", method="CARD",
                                amount=1000, cardToken="tok")
            with self.subTest(reservation=rid):
                self.assertEqual(response.status_code, 409)

    def test_wrong_amount_is_422_with_expected_amount(self):
        response = self.rental_r_pending(amount=250)
        self.assertEqual(response.status_code, 422)
        detail = response.json()["details"][0]
        self.assertEqual(detail["field"], "amount")
        self.assertIn("270.000", detail["message"])

    def test_unknown_reservation_is_404(self):
        response = self.pay(admin(), reservationId=str(uuid.uuid4()), type="RENTAL", method="CARD",
                            amount=1, cardToken="tok")
        self.assertEqual(response.status_code, 404)

    def test_reservation_service_down_or_unreadable(self):
        for rid, expected in ((FakeReservationClient.UNAVAILABLE_ID, 503), (FakeReservationClient.BAD_RESPONSE_ID, 502)):
            response = self.pay(admin(), reservationId=str(rid), type="RENTAL", method="CARD", amount=1, cardToken="t")
            with self.subTest(expected=expected):
                self.assertEqual(response.status_code, expected)

    def test_client_paying_someone_elses_reservation_is_403(self):
        self.assertEqual(self.rental_r_pending(headers=client2()).status_code, 403)

    def test_cash_is_reserved_to_agents(self):
        self.assertEqual(self.rental_r_pending(method="CASH").status_code, 403)
        response = self.rental_r_pending(headers=agent(), method="CASH")
        self.assertEqual(response.status_code, 201)
        self.assertEqual(response.json()["userId"], str(CLIENT1))  # paid for the reservation's client

    def test_validation_errors_are_400_in_common_format(self):
        response = self.rental_r_pending(amount=270.0001, type="REFUND", reservationId="nope")
        self.assertEqual(response.status_code, 400)
        fields = {d["field"] for d in response.json()["details"]}
        self.assertEqual(fields, {"amount", "type", "reservationId"})

    def test_no_authentication_is_401_and_unknown_role_is_401(self):
        self.assertEqual(self.client.get("/api/payments").status_code, 401)
        self.assertEqual(self.client.get("/api/payments", headers={"X-User-Id": str(CLIENT1),
                                                                    "X-User-Roles": "ROOT"}).status_code, 401)


class TransferAdminTests(ApiTestCase):
    def test_transfer_pending_then_admin_validates(self):
        created = self.rental_r_pending(method="TRANSFER", cardToken=None)
        self.assertEqual(created.json()["status"], "PENDING")
        self.assertEqual(self.publisher.published, [])
        pid = created.json()["id"]
        # a second payment of the same type while one is pending -> 409
        self.assertEqual(self.rental_r_pending().status_code, 409)

        url = f"/api/payments/{pid}"
        self.assertEqual(self.client.patch(url, {"status": "SUCCEEDED"}, content_type="application/json",
                                           headers=client1(idempotency_key=False)).status_code, 403)
        response = self.client.patch(url, {"status": "SUCCEEDED", "transactionRef": "VIR-1"},
                                     content_type="application/json", headers=admin(idempotency_key=False))
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json()["transactionRef"], "VIR-1")
        self.assertTrue(Invoice.objects.filter(reservation_id=R_PENDING).exists())
        self.assertEqual(len(self.publisher.published), 1)
        # only PENDING payments can be modified
        again = self.client.patch(url, {"status": "FAILED"}, content_type="application/json",
                                  headers=admin(idempotency_key=False))
        self.assertEqual(again.status_code, 409)

    def test_put_is_not_allowed(self):
        response = self.client.put(f"/api/payments/{P_CONFIRMED}", {}, content_type="application/json",
                                   headers=admin(idempotency_key=False))
        self.assertEqual(response.status_code, 405)
        self.assertEqual(response.json()["error"], "METHOD_NOT_ALLOWED")


class DeleteTests(ApiTestCase):
    def test_delete_rules(self):
        headers = admin(idempotency_key=False)
        self.assertEqual(self.client.delete(f"/api/payments/{P_CONFIRMED}", headers=headers).status_code, 409)
        failed = self.rental_r_pending(cardToken="fail_x").json()["id"]
        self.assertEqual(self.client.delete(f"/api/payments/{failed}", headers=client1(idempotency_key=False)).status_code, 403)
        self.assertEqual(self.client.delete(f"/api/payments/{failed}", headers=headers).status_code, 204)
        self.assertEqual(self.client.delete(f"/api/payments/{failed}", headers=headers).status_code, 404)


class PayPendingFeeTests(ApiTestCase):
    def setUp(self):
        super().setUp()
        from payments.services import handle_return_settled
        handle_return_settled(uuid.uuid4(), {
            "reservationId": str(R_COMPLETED), "userId": "611f32de-ce7b-4927-b1c4-d113b6479d59",
            "contractId": str(uuid.uuid4()), "extraFees": [{"type": "FUEL", "amount": 35.0}],
            "depositToRelease": 0,
        })
        self.fee = Payment.objects.get(reservation_id=R_COMPLETED, type="EXTRA_FEE")

    def pay_fee(self, headers, **body):
        return self.client.post(f"/api/payments/{self.fee.id}/pay", body, content_type="application/json",
                                headers=headers)

    def test_refused_card_is_422_and_fee_stays_pending(self):
        response = self.pay_fee(client2(), method="CARD", cardToken="fail_x")
        self.assertEqual(response.status_code, 422)
        self.fee.refresh_from_db()
        self.assertEqual((self.fee.status, self.fee.method), ("PENDING", None))

    def test_owner_pays_fee(self):
        self.assertEqual(self.pay_fee(client1(), method="CARD", cardToken="tok").status_code, 403)
        response = self.pay_fee(client2(), method="CARD", cardToken="tok")
        self.assertEqual(response.status_code, 200)
        self.assertEqual((response.json()["status"], response.json()["method"]), ("SUCCEEDED", "CARD"))
        self.assertEqual(self.publisher.published[-1][1]["data"]["type"], "EXTRA_FEE")
        self.assertEqual(self.pay_fee(client2(), method="CARD", cardToken="tok").status_code, 409)

    def test_fee_on_invoice_cannot_be_deleted(self):
        response = self.client.delete(f"/api/payments/{self.fee.id}", headers=admin(idempotency_key=False))
        self.assertEqual(response.status_code, 409)


class ReadTests(ApiTestCase):
    def test_client_sees_only_own_payments_paginated(self):
        body = self.client.get("/api/payments", headers=client1(idempotency_key=False)).json()
        self.assertEqual(set(body), {"items", "page", "size", "total"})
        self.assertEqual([p["id"] for p in body["items"]], [str(P_CONFIRMED)])
        all_ = self.client.get("/api/payments", {"size": 1}, headers=agent(idempotency_key=False)).json()
        self.assertEqual((all_["total"], len(all_["items"])), (2, 1))

    def test_filters_and_invalid_query(self):
        body = self.client.get("/api/payments", {"reservationId": str(R_COMPLETED)},
                               headers=admin(idempotency_key=False)).json()
        self.assertEqual([p["id"] for p in body["items"]], [str(P_COMPLETED)])
        bad = self.client.get("/api/payments", {"status": "PAID"}, headers=admin(idempotency_key=False))
        self.assertEqual(bad.status_code, 400)
        self.assertEqual(bad.json()["details"][0]["field"], "status")

    def test_retrieve_rules(self):
        self.assertEqual(self.client.get(f"/api/payments/{P_CONFIRMED}", headers=client1(idempotency_key=False)).status_code, 200)
        self.assertEqual(self.client.get(f"/api/payments/{P_CONFIRMED}", headers=client2(idempotency_key=False)).status_code, 403)
        self.assertEqual(self.client.get(f"/api/payments/{uuid.uuid4()}", headers=admin(idempotency_key=False)).status_code, 404)

    def test_invoices(self):
        body = self.client.get("/api/invoices", headers=client2(idempotency_key=False)).json()
        self.assertEqual(body["total"], 1)
        invoice = body["items"][0]
        self.assertEqual(invoice["lines"][0]["paymentId"], str(P_COMPLETED))
        self.assertEqual(self.client.get(f"/api/invoices/{invoice['id']}", headers=client1(idempotency_key=False)).status_code, 403)
        self.assertEqual(self.client.post("/api/invoices", {}, headers=admin(idempotency_key=False)).status_code, 405)


class InternalApiTests(ApiTestCase):
    def test_contract_of_internal_payments(self):
        response = self.client.get("/internal/payments", {"reservationId": str(R_CONFIRMED)}, headers=INTERNAL)
        self.assertEqual(response.status_code, 200)
        [payment] = response.json()  # plain list, not paginated (docs/internal-api.md)
        self.assertEqual(set(payment), {"id", "reservationId", "userId", "amount", "currency", "method",
                                        "type", "status", "createdAt"})
        deposit = self.client.get("/internal/payments", {"reservationId": str(R_CONFIRMED), "type": "DEPOSIT",
                                                         "status": "SUCCEEDED"}, headers=INTERNAL)
        self.assertEqual(deposit.json(), [])

    def test_internal_access_rules(self):
        self.assertEqual(self.client.get("/internal/payments", headers=INTERNAL).status_code, 400)
        self.assertEqual(self.client.get("/internal/payments", {"reservationId": str(R_CONFIRMED)},
                                         headers=client1(idempotency_key=False)).status_code, 403)
        self.assertEqual(self.client.get("/internal/payments", {"reservationId": str(R_CONFIRMED)}).status_code, 401)
