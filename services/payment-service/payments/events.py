"""
Outgoing RabbitMQ events.

Envelope (docs/conventions.md): {"eventId", "type", "occurredAt", "data"}, JSON, persistent.
The producer only publishes to the queue name (the consumer declares the queue).

Publication happens INSIDE the database transaction of the payment (services.py):
if the publish raises, the exception propagates, transaction.atomic rolls back the
payment, and the API answers 503. See README "Transactions et publication".

EVENT_PUBLISHER setting:
- "log"      : LoggingEventPublisher, only logs the message (tests, development without RabbitMQ).
- "rabbitmq" : PikaEventPublisher (payments/rabbitmq.py), publisher confirms.
"""
import json
import logging
import uuid
from decimal import Decimal
from typing import Protocol

from django.conf import settings
from django.utils import timezone

from payments.correlation import get_correlation_id
from payments.exceptions import ServiceUnavailableError

logger = logging.getLogger(__name__)

QUEUE_PAYMENT_SUCCEEDED = "reservation.payment_succeeded"


class EventPublishError(ServiceUnavailableError):
    default_message = "Le courtier de messages (RabbitMQ) est indisponible, paiement annulé"


def _json_default(value):
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, uuid.UUID):
        return str(value)
    raise TypeError(f"Not JSON serializable: {type(value).__name__}")


def build_event(event_type: str, data: dict) -> dict:
    return {
        "eventId": str(uuid.uuid4()),
        "type": event_type,
        "occurredAt": timezone.now().strftime("%Y-%m-%dT%H:%M:%SZ"),
        "data": data,
    }


def to_json_bytes(event: dict) -> bytes:
    return json.dumps(event, default=_json_default, ensure_ascii=False).encode("utf-8")


class EventPublisher(Protocol):
    def publish(self, queue: str, event: dict) -> None:
        """Publish or raise EventPublishError."""
        ...


class LoggingEventPublisher:
    """Step 2 stand-in: records events in memory and in the log."""

    def __init__(self):
        self.published: list[tuple[str, dict]] = []

    def publish(self, queue: str, event: dict) -> None:
        self.published.append((queue, event))
        logger.info("[event] %s <- %s (correlationId=%s)", queue, to_json_bytes(event).decode(), get_correlation_id())


_publisher: EventPublisher | None = None


def get_event_publisher() -> EventPublisher:
    global _publisher
    if _publisher is None:
        kind = getattr(settings, "EVENT_PUBLISHER", "log").lower()
        if kind == "log":
            _publisher = LoggingEventPublisher()
        elif kind == "rabbitmq":
            from payments.rabbitmq import PikaEventPublisher
            _publisher = PikaEventPublisher()
        else:
            raise ValueError(f"EVENT_PUBLISHER inconnu : {kind!r} (attendu : log | rabbitmq)")
    return _publisher


def set_event_publisher(publisher: EventPublisher | None) -> None:
    """Used by tests to plug a fake/failing publisher."""
    global _publisher
    _publisher = publisher


def payment_succeeded_event(payment) -> dict:
    return build_event("PaymentSucceeded", {
        "paymentId": str(payment.id),
        "reservationId": str(payment.reservation_id),
        "userId": str(payment.user_id),
        "amount": payment.amount,
        "currency": payment.currency,
        "type": payment.type,
    })
