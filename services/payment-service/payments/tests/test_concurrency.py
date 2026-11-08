"""
Race condition: two payments for the same reservation at the same time.
Both pass the read-only "already paid?" check; the partial unique constraint in
PostgreSQL lets only one commit, the other gets 409. Needs real transactions,
hence TransactionTestCase and one DB connection per thread.
"""
import threading
import time
import uuid
from decimal import Decimal
from unittest import mock

from django.db import connection
from django.test import TransactionTestCase

from payments import events
from payments.auth import Principal
from payments.clients.reservations import FakeReservationClient
from payments.demo_data import CLIENT1, R_PENDING
from payments.exceptions import ConflictError
from payments.models import Payment
from payments.services import create_payment

from .helpers import RecordingPublisher


class SlowFakeClient(FakeReservationClient):
    """Widens the race window: both threads read 'not paid yet' before either inserts."""

    def get_reservation(self, reservation_id):
        time.sleep(0.3)
        return super().get_reservation(reservation_id)


class ConcurrentPaymentTests(TransactionTestCase):
    def setUp(self):
        events.set_event_publisher(RecordingPublisher())

    def tearDown(self):
        events.set_event_publisher(None)

    def test_only_one_of_two_simultaneous_payments_succeeds(self):
        principal = Principal(user_id=CLIENT1, roles=frozenset({"CLIENT"}))
        outcomes, barrier = [], threading.Barrier(2)

        def attempt():
            try:
                barrier.wait()
                create_payment(principal, reservation_id=R_PENDING, type="RENTAL", method="CARD",
                               amount=Decimal("270.000"), card_token="tok", idempotency_key=str(uuid.uuid4()))
                outcomes.append("created")
            except ConflictError:
                outcomes.append("409")
            finally:
                connection.close()

        with mock.patch("payments.services.get_reservation_client", return_value=SlowFakeClient()):
            threads = [threading.Thread(target=attempt) for _ in range(2)]
            for t in threads:
                t.start()
            for t in threads:
                t.join()

        self.assertEqual(sorted(outcomes), ["409", "created"])
        self.assertEqual(Payment.objects.filter(reservation_id=R_PENDING, status="SUCCEEDED").count(), 1)
