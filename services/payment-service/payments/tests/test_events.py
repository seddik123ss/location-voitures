"""
Consumer-side business logic, fed with the OFFICIAL example messages of docs/events/
(same JSON that reservation-service and contract-service publish).
"""
import uuid
from decimal import Decimal
from io import StringIO

from django.core.management import call_command
from django.test import TestCase

from payments.demo_data import CLIENT1, CLIENT2, P_CONFIRMED, R_COMPLETED, R_CONFIRMED
from payments.events import payment_succeeded_event
from payments.models import Invoice, Payment, ProcessedEvent
from payments.services import InvalidEvent, handle_reservation_cancelled, handle_return_settled

from .helpers import load_event


class EventTestCase(TestCase):
    def setUp(self):
        call_command("seed_demo", stdout=StringIO())


class PaymentSucceededFormatTests(EventTestCase):
    def test_matches_official_example(self):
        official = load_event("PaymentSucceeded")
        ours = payment_succeeded_event(Payment.objects.get(pk=P_CONFIRMED))
        self.assertEqual(set(ours), set(official))
        self.assertEqual(set(ours["data"]), set(official["data"]))
        for key in ("paymentId", "reservationId", "userId", "currency", "type"):
            self.assertEqual(str(ours["data"][key]), official["data"][key])
        self.assertEqual(float(ours["data"]["amount"]), official["data"]["amount"])


class ReservationCancelledTests(EventTestCase):
    def test_official_example_refunds_every_succeeded_payment(self):
        event = load_event("ReservationCancelled")
        Payment.objects.create(reservation_id=R_CONFIRMED, user_id=CLIENT1, amount=Decimal("500"),
                               method="CARD", type="DEPOSIT", status="SUCCEEDED")
        pending = Payment.objects.create(reservation_id=R_CONFIRMED, user_id=CLIENT1, amount=Decimal("1"),
                                         type="EXTRA_FEE", fee_type="LATE", status="PENDING")

        result = handle_reservation_cancelled(event["eventId"], event["data"])

        self.assertFalse(result.duplicate)
        self.assertEqual(sorted(r.amount for r in result.refunds), [Decimal("450.000"), Decimal("500.000")])
        self.assertTrue(all(r.type == "REFUND" and r.status == "SUCCEEDED" for r in result.refunds))
        self.assertEqual(Payment.objects.get(pk=P_CONFIRMED).status, "REFUNDED")
        pending.refresh_from_db()
        self.assertEqual(pending.status, "FAILED")
        self.assertTrue(ProcessedEvent.objects.filter(event_id=event["eventId"]).exists())

    def test_duplicate_event_is_ignored(self):
        event = load_event("ReservationCancelled")
        handle_reservation_cancelled(event["eventId"], event["data"])
        count = Payment.objects.count()
        result = handle_reservation_cancelled(event["eventId"], event["data"])
        self.assertTrue(result.duplicate)
        self.assertEqual(Payment.objects.count(), count)

    def test_invalid_data_is_rejected_and_not_marked_processed(self):
        event_id = uuid.uuid4()
        with self.assertRaises(InvalidEvent):
            handle_reservation_cancelled(event_id, {"userId": str(CLIENT1)})
        self.assertFalse(ProcessedEvent.objects.filter(event_id=event_id).exists())


class ReturnSettledTests(EventTestCase):
    def test_official_example_without_paid_deposit_creates_fees_and_invoice(self):
        event = load_event("ReturnSettled")
        with self.assertLogs("payments.services", "WARNING"):
            result = handle_return_settled(event["eventId"], event["data"])

        self.assertEqual(result.refunds, [])
        self.assertEqual(sorted((f.fee_type, f.amount) for f in result.fees),
                         [("FUEL", Decimal("35.000")), ("LATE", Decimal("160.000"))])
        self.assertTrue(all(f.status == "PENDING" and f.method is None and f.user_id == CLIENT2 for f in result.fees))
        self.assertEqual(result.invoice.total, Decimal("195.000"))
        self.assertEqual(result.invoice.lines.count(), 2)

    def test_paid_deposit_is_refunded(self):
        deposit = Payment.objects.create(reservation_id=R_COMPLETED, user_id=CLIENT2, amount=Decimal("1000"),
                                         method="CASH", type="DEPOSIT", status="SUCCEEDED")
        event = load_event("ReturnSettled")
        result = handle_return_settled(event["eventId"], event["data"])
        [refund] = result.refunds
        self.assertEqual((refund.amount, refund.original_payment_id, refund.method),
                         (Decimal("1000.000"), deposit.id, "CASH"))
        deposit.refresh_from_db()
        self.assertEqual(deposit.status, "REFUNDED")

    def test_duplicate_event_is_ignored(self):
        event = load_event("ReturnSettled")
        handle_return_settled(event["eventId"], event["data"])
        invoices = Invoice.objects.count()
        self.assertTrue(handle_return_settled(event["eventId"], event["data"]).duplicate)
        self.assertEqual(Invoice.objects.count(), invoices)

    def test_release_above_deposit_rolls_back_everything(self):
        Payment.objects.create(reservation_id=R_COMPLETED, user_id=CLIENT2, amount=Decimal("100"),
                               method="CARD", type="DEPOSIT", status="SUCCEEDED")
        event = load_event("ReturnSettled")
        with self.assertRaises(InvalidEvent):
            handle_return_settled(event["eventId"], event["data"])
        self.assertFalse(Payment.objects.filter(type="EXTRA_FEE").exists())
        self.assertFalse(ProcessedEvent.objects.filter(event_id=event["eventId"]).exists())

    def test_invalid_fee_type_is_rejected(self):
        data = {**load_event("ReturnSettled")["data"], "extraFees": [{"type": "PARKING", "amount": 10}]}
        with self.assertRaises(InvalidEvent):
            handle_return_settled(uuid.uuid4(), data)
