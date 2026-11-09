"""
python manage.py consume_events

Long-running RabbitMQ consumer of payment-service (run it in its own terminal, next to runserver).
Declares and consumes:
  payment.reservation_cancelled  (ReservationCancelled, from reservation-service)
  payment.return_settled         (ReturnSettled, from contract-service)
and their dead-letter queues <queue>.dlq. Ctrl+C to stop.
"""
import logging
import signal
import threading

from django.core.management.base import BaseCommand

from payments.rabbitmq import EventConsumer, Subscription
from payments.services import handle_reservation_cancelled, handle_return_settled

SUBSCRIPTIONS = [
    Subscription("payment.reservation_cancelled", "ReservationCancelled", handle_reservation_cancelled),
    Subscription("payment.return_settled", "ReturnSettled", handle_return_settled),
]


class Command(BaseCommand):
    help = "Consume payment.reservation_cancelled and payment.return_settled (RabbitMQ)."

    def handle(self, *args, **options):
        logging.getLogger("payments").setLevel(logging.INFO)
        stop = threading.Event()
        signal.signal(signal.SIGINT, lambda *_: stop.set())
        self.stdout.write(self.style.SUCCESS("payment-service consumer started (Ctrl+C to stop)"))
        EventConsumer(SUBSCRIPTIONS).run_forever(stop=stop)
        self.stdout.write("Consumer stopped.")
