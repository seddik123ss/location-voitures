"""
RabbitMQ with pika (docs/events/README.md).

PRODUCER - PikaEventPublisher (EVENT_PUBLISHER=rabbitmq)
  - default exchange, routing key = queue name: the producer never declares the queue
    (the consumer owns it);
  - JSON, content_type application/json, delivery_mode 2 (persistent);
  - publisher confirms + mandatory=True: basic_publish returns only once RabbitMQ has
    accepted the message. Broker down, message refused (nack) or no queue to receive it
    (unroutable) -> EventPublishError (503) -> the payment transaction is rolled back.
  - pika's BlockingConnection is not thread-safe: one connection per thread (runserver
    serves requests in threads), reopened once if it was closed.

CONSUMER - EventConsumer (python manage.py consume_events)
  - declares its queues (durable) and their dead-letter queues <queue>.dlq;
  - manual ack, prefetch 1 (one message at a time);
  - success or duplicate eventId -> ack;
  - malformed message (bad JSON, bad envelope, wrong type, InvalidEvent) -> nack without
    requeue -> RabbitMQ moves it to <queue>.dlq (retrying cannot help);
  - other error (e.g. database down): first delivery -> nack with requeue (one retry);
    already redelivered -> nack without requeue -> <queue>.dlq.
"""
from __future__ import annotations

import json
import logging
import threading
import time
import uuid
from dataclasses import dataclass
from typing import Callable

import pika
from django.conf import settings
from django.db import close_old_connections
from django.db import connection as db_connection
from pika import exceptions as pika_exc

from .correlation import get_correlation_id
from .events import EventPublishError, to_json_bytes
from .services import InvalidEvent

logger = logging.getLogger(__name__)


def connection_parameters() -> pika.URLParameters:
    params = pika.URLParameters(settings.RABBITMQ_URL)
    params.connection_attempts = 1
    params.socket_timeout = 3
    params.blocked_connection_timeout = 5
    params.heartbeat = 30
    return params


# --- Producer -------------------------------------------------------------------

class PikaEventPublisher:
    def __init__(self, parameters_factory: Callable[[], pika.ConnectionParameters] = connection_parameters):
        self._parameters_factory = parameters_factory
        self._local = threading.local()

    def _channel(self, fresh: bool = False):
        channel = getattr(self._local, "channel", None)
        if fresh or channel is None or channel.is_closed:
            self._close()
            connection = pika.BlockingConnection(self._parameters_factory())
            channel = connection.channel()
            channel.confirm_delivery()
            self._local.connection, self._local.channel = connection, channel
        return channel

    def _close(self):
        connection = getattr(self._local, "connection", None)
        if connection is not None and connection.is_open:
            try:
                connection.close()
            except Exception:  # noqa: BLE001
                pass
        self._local.connection = self._local.channel = None

    def publish(self, queue: str, event: dict) -> None:
        headers = {"X-Correlation-Id": get_correlation_id()} if get_correlation_id() else None
        properties = pika.BasicProperties(
            content_type="application/json", delivery_mode=pika.DeliveryMode.Persistent,
            message_id=event["eventId"], type=event["type"], timestamp=int(time.time()), headers=headers,
        )
        body = to_json_bytes(event)
        for attempt in (1, 2):
            try:
                self._channel(fresh=attempt == 2).basic_publish(
                    exchange="", routing_key=queue, body=body, properties=properties, mandatory=True,
                )
                logger.info("Published %s %s on %s", event["type"], event["eventId"], queue)
                return
            except pika_exc.UnroutableError:
                self._close()
                raise EventPublishError(
                    f"Aucune file '{queue}' dans RabbitMQ : le service consommateur ne l'a pas encore déclarée ; "
                    "paiement annulé pour ne pas perdre le message"
                )
            except pika_exc.NackError:
                self._close()
                raise EventPublishError("RabbitMQ a refusé le message ; paiement annulé")
            except (pika_exc.StreamLostError, pika_exc.ConnectionClosed, pika_exc.ChannelClosed) as exc:
                self._close()
                if attempt == 2:
                    logger.error("RabbitMQ connection lost twice: %s", exc)
                    raise EventPublishError()
                logger.warning("RabbitMQ connection was closed (%s), reconnecting", exc)
            except pika_exc.AMQPError as exc:
                self._close()
                logger.error("RabbitMQ unavailable (%s): %r", settings.RABBITMQ_URL.split("@")[-1], exc)
                raise EventPublishError()


# --- Consumer -------------------------------------------------------------------

class MalformedMessage(ValueError):
    """Unusable message: goes straight to the .dlq."""


@dataclass(frozen=True)
class Subscription:
    queue: str
    event_type: str
    handler: Callable  # handler(event_id, data, queue=...) -> EventResult


def declare_queue(channel, queue: str) -> None:
    """Durable queue + its dead-letter queue <queue>.dlq (default exchange)."""
    channel.queue_declare(queue=f"{queue}.dlq", durable=True)
    channel.queue_declare(queue=queue, durable=True, arguments={
        "x-dead-letter-exchange": "",
        "x-dead-letter-routing-key": f"{queue}.dlq",
    })


def parse_envelope(body: bytes, expected_type: str) -> tuple[uuid.UUID, dict]:
    try:
        event = json.loads(body)
    except (ValueError, UnicodeDecodeError) as exc:
        raise MalformedMessage(f"JSON invalide : {exc}")
    if not isinstance(event, dict):
        raise MalformedMessage("l'enveloppe doit être un objet JSON")
    try:
        event_id = uuid.UUID(str(event["eventId"]))
    except (KeyError, ValueError):
        raise MalformedMessage("eventId manquant ou invalide")
    if event.get("type") != expected_type:
        raise MalformedMessage(f"type {event.get('type')!r} inattendu sur cette file (attendu {expected_type!r})")
    if not isinstance(event.get("data"), dict):
        raise MalformedMessage("data manquant")
    return event_id, event["data"]


class EventConsumer:
    def __init__(self, subscriptions: list[Subscription]):
        self.subscriptions = {s.queue: s for s in subscriptions}

    def handle_delivery(self, channel, method, properties, body: bytes) -> str:
        """Process one message and ack/nack it. Returns the outcome (used by logs and tests)."""
        subscription = self.subscriptions[method.routing_key]
        queue = subscription.queue
        correlation_id = (getattr(properties, "headers", None) or {}).get("X-Correlation-Id")
        if not db_connection.in_atomic_block:  # never true in the real consumer; true inside TestCase
            close_old_connections()  # long-running process: drop stale/broken DB connections
        try:
            event_id, data = parse_envelope(body, subscription.event_type)
            result = subscription.handler(event_id, data, queue=queue)
        except (MalformedMessage, InvalidEvent) as exc:
            logger.error("[%s] rejected to %s.dlq: %s (correlationId=%s)", queue, queue, exc, correlation_id)
            channel.basic_nack(delivery_tag=method.delivery_tag, requeue=False)
            return "dlq"
        except Exception:  # noqa: BLE001 - transient failure (DB down, ...)
            if method.redelivered:
                logger.exception("[%s] failed again after redelivery, moved to %s.dlq", queue, queue)
                channel.basic_nack(delivery_tag=method.delivery_tag, requeue=False)
                return "dlq"
            logger.exception("[%s] failed, requeued once", queue)
            channel.basic_nack(delivery_tag=method.delivery_tag, requeue=True)
            return "requeued"
        channel.basic_ack(delivery_tag=method.delivery_tag)
        if result.duplicate:
            logger.info("[%s] duplicate eventId %s ignored", queue, event_id)
            return "duplicate"
        logger.info("[%s] %s %s processed: %d refund(s), %d fee(s), invoice=%s (correlationId=%s)",
                    queue, subscription.event_type, event_id, len(result.refunds), len(result.fees),
                    result.invoice.number if result.invoice else None, correlation_id)
        return "processed"

    def run_forever(self, parameters_factory=connection_parameters, stop: threading.Event | None = None):
        """Consume until stopped; reconnects with backoff if RabbitMQ goes away."""
        stop = stop or threading.Event()
        delay = 1
        while not stop.is_set():
            try:
                connection = pika.BlockingConnection(parameters_factory())
                channel = connection.channel()
                channel.basic_qos(prefetch_count=1)
                for queue in self.subscriptions:
                    declare_queue(channel, queue)
                    channel.basic_consume(queue=queue, on_message_callback=self.handle_delivery, auto_ack=False)
                logger.info("Consuming %s (prefetch 1, manual ack)", ", ".join(self.subscriptions))
                delay = 1
                while not stop.is_set():
                    connection.process_data_events(time_limit=1)
                connection.close()
            except pika_exc.AMQPConnectionError as exc:
                logger.warning("RabbitMQ unreachable (%r), retrying in %ss", exc, delay)
                stop.wait(delay)
                delay = min(delay * 2, 30)
