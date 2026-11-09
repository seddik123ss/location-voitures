"""
Consumer-side business logic, fed with the OFFICIAL example messages of docs/events/
(same JSON that reservation-service and contract-service publish), on top of the demo
data of docs/demo-ids.md (seed_demo).
"""
import uuid
from decimal import Decimal
from io import StringIO

from django.core.management import call_command
from django.test import TestCase

from payments.demo_data import (
    CLIENT1,
    P_COMPLETED_DEPOSIT,
    P_CONFIRMED,
    P_CONFIRMED_DEPOSIT,
    R_COMPLETED,
    R_CONFIRMED,
    R_PENDING,
)
from payments.events import payment_succeeded_event
from payments.models import Invoice, Payment, ProcessedEvent
from payments.services import InvalidEvent, handle_reservation_cancelled, handle_return_settled

from .helpers import load_event


def return_settled(reservation_id, deposit_to_release, fees=(("LATE", 160.0), ("FUEL", 35.0)), user=CLIENT1):
    """A new ReturnSettled event (fresh eventId) shaped like docs/events/ReturnSettled.json."""
    data = {**load_event("ReturnSettled")["data"], "reservationId": str(reservation_id), "userId": str(user),
            "extraFees": [{"type": t, "amount": a} for t, a in fees], "depositToRelease": deposit_to_release}
    return uuid.uuid4(), data


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
        event = load_event("ReservationCancelled")  # r-confirmed: RENTAL 450 + DEPOSIT 500 paid
        pending = Payment.objects.create(reservation_id=R_CONFIRMED, user_id=CLIENT1, amount=Decimal("1"),
                                         type="EXTRA_FEE", fee_type="LATE", status="PENDING")

        result = handle_reservation_cancelled(event["eventId"], event["data"])

        self.assertFalse(result.duplicate)
        self.assertEqual(sorted(r.amount for r in result.refunds), [Decimal("450.000"), Decimal("500.000")])
        self.assertTrue(all(r.type == "REFUND" and r.status == "SUCCEEDED" for r in result.refunds))
        self.assertEqual(Payment.objects.get(pk=P_CONFIRMED).status, "REFUNDED")
        self.assertEqual(Payment.objects.get(pk=P_CONFIRMED_DEPOSIT).status, "REFUNDED")
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
    def test_demo_data_already_contains_the_official_example(self):
        """seed_demo = state after docs/events/ReturnSettled.json: replaying it changes nothing."""
        self.assertEqual(Payment.objects.get(pk=P_COMPLETED_DEPOSIT).status, "REFUNDED")
        self.assertEqual(Payment.objects.filter(reservation_id=R_COMPLETED, type="EXTRA_FEE", status="PENDING").count(), 2)
        event = load_event("ReturnSettled")
        count, invoices = Payment.objects.count(), Invoice.objects.count()
        self.assertTrue(handle_return_settled(event["eventId"], event["data"]).duplicate)
        self.assertEqual((Payment.objects.count(), Invoice.objects.count()), (count, invoices))

    def test_paid_deposit_is_refunded_and_fees_billed(self):
        event_id, data = return_settled(R_CONFIRMED, 500.0)
        result = handle_return_settled(event_id, data)
        [refund] = result.refunds
        self.assertEqual((refund.amount, refund.original_payment_id, refund.method),
                         (Decimal("500.000"), P_CONFIRMED_DEPOSIT, "CARD"))
        self.assertEqual(Payment.objects.get(pk=P_CONFIRMED_DEPOSIT).status, "REFUNDED")
        self.assertEqual(sorted((f.fee_type, f.amount) for f in result.fees),
                         [("FUEL", Decimal("35.000")), ("LATE", Decimal("160.000"))])
        self.assertTrue(all(f.status == "PENDING" and f.method is None and f.user_id == CLIENT1 for f in result.fees))
        self.assertEqual(result.invoice.total, Decimal("195.000"))
        self.assertEqual(result.invoice.lines.count(), 2)

    def test_no_paid_deposit_logs_warning_and_still_bills_fees(self):
        event_id, data = return_settled(R_PENDING, 500.0)
        with self.assertLogs("payments.services", "WARNING"):
            result = handle_return_settled(event_id, data)
        self.assertEqual(result.refunds, [])
        self.assertEqual(len(result.fees), 2)

    def test_duplicate_event_is_ignored(self):
        event_id, data = return_settled(R_CONFIRMED, 500.0)
        handle_return_settled(event_id, data)
        invoices = Invoice.objects.count()
        self.assertTrue(handle_return_settled(event_id, data).duplicate)
        self.assertEqual(Invoice.objects.count(), invoices)

    def test_release_above_deposit_rolls_back_everything(self):
        event_id, data = return_settled(R_CONFIRMED, 600.0)  # deposit paid: 500
        with self.assertRaises(InvalidEvent):
            handle_return_settled(event_id, data)
        self.assertFalse(Payment.objects.filter(reservation_id=R_CONFIRMED, type="EXTRA_FEE").exists())
        self.assertEqual(Payment.objects.get(pk=P_CONFIRMED_DEPOSIT).status, "SUCCEEDED")
        self.assertFalse(ProcessedEvent.objects.filter(event_id=event_id).exists())

    def test_invalid_fee_type_is_rejected(self):
        event_id, data = return_settled(R_CONFIRMED, 0, fees=(("PARKING", 10),))
        with self.assertRaises(InvalidEvent):
            handle_return_settled(event_id, data)
