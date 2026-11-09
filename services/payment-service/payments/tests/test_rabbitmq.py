"""
RabbitMQ producer/consumer logic, without a broker: pika is mocked, the consumer is fed
with the official messages of docs/events/ and a fake channel records ack/nack.
"""
import json
from dataclasses import replace
from io import StringIO
from types import SimpleNamespace
from unittest import mock

import pika
from django.core.management import call_command
from django.test import SimpleTestCase, TestCase
from pika import exceptions as pika_exc

from payments.events import EventPublishError, build_event
from payments.management.commands.consume_events import SUBSCRIPTIONS
from payments.models import Payment
from payments.rabbitmq import EventConsumer, PikaEventPublisher, declare_queue

from .helpers import load_event

CANCELLED, SETTLED = "payment.reservation_cancelled", "payment.return_settled"


def delivery(queue, redelivered=False, tag=1):
    return SimpleNamespace(routing_key=queue, delivery_tag=tag, redelivered=redelivered)


def body(event):
    return json.dumps(event).encode()


class ConsumerTests(TestCase):
    def setUp(self):
        call_command("seed_demo", stdout=StringIO())
        self.consumer = EventConsumer(SUBSCRIPTIONS)
        self.channel = mock.Mock()

    def deliver(self, queue, payload, redelivered=False):
        raw = payload if isinstance(payload, bytes) else body(payload)
        return self.consumer.handle_delivery(self.channel, delivery(queue, redelivered),
                                             SimpleNamespace(headers={"X-Correlation-Id": "c-1"}), raw)

    def test_official_reservation_cancelled_is_processed_and_acked(self):
        self.assertEqual(self.deliver(CANCELLED, load_event("ReservationCancelled")), "processed")
        self.channel.basic_ack.assert_called_once_with(delivery_tag=1)
        self.assertEqual(Payment.objects.filter(type="REFUND").count(), 1)

    def test_official_return_settled_is_processed_and_acked(self):
        self.assertEqual(self.deliver(SETTLED, load_event("ReturnSettled")), "processed")
        self.channel.basic_ack.assert_called_once()
        self.assertEqual(Payment.objects.filter(type="EXTRA_FEE", status="PENDING").count(), 2)

    def test_duplicate_is_acked_without_effect(self):
        event = load_event("ReservationCancelled")
        self.deliver(CANCELLED, event)
        self.assertEqual(self.deliver(CANCELLED, event), "duplicate")
        self.assertEqual(self.channel.basic_ack.call_count, 2)
        self.assertEqual(Payment.objects.filter(type="REFUND").count(), 1)

    def test_malformed_messages_go_straight_to_dlq(self):
        cases = {
            "not json": b"{oops",
            "no eventId": {"type": "ReservationCancelled", "data": {}},
            "wrong type for queue": load_event("ReturnSettled"),
            "invalid data": {**load_event("ReservationCancelled"), "data": {"userId": "x"}},
        }
        for name, payload in cases.items():
            self.channel.reset_mock()
            with self.subTest(name):
                self.assertEqual(self.deliver(CANCELLED, payload), "dlq")
                self.channel.basic_nack.assert_called_once_with(delivery_tag=1, requeue=False)
                self.channel.basic_ack.assert_not_called()

    def test_transient_error_is_requeued_once_then_dead_lettered(self):
        event = load_event("ReservationCancelled")
        failing = mock.Mock(side_effect=RuntimeError("db down"))
        self.consumer.subscriptions[CANCELLED] = replace(self.consumer.subscriptions[CANCELLED], handler=failing)
        self.assertEqual(self.deliver(CANCELLED, event), "requeued")
        self.channel.basic_nack.assert_called_with(delivery_tag=1, requeue=True)
        self.assertEqual(self.deliver(CANCELLED, event, redelivered=True), "dlq")
        self.channel.basic_nack.assert_called_with(delivery_tag=1, requeue=False)
        self.assertFalse(Payment.objects.filter(type="REFUND").exists())


class DeclareQueueTests(SimpleTestCase):
    def test_queue_is_durable_with_dead_letter_queue(self):
        channel = mock.Mock()
        declare_queue(channel, CANCELLED)
        channel.queue_declare.assert_any_call(queue=f"{CANCELLED}.dlq", durable=True)
        channel.queue_declare.assert_any_call(queue=CANCELLED, durable=True, arguments={
            "x-dead-letter-exchange": "", "x-dead-letter-routing-key": f"{CANCELLED}.dlq"})


class PublisherTests(SimpleTestCase):
    def setUp(self):
        self.channel = mock.Mock(is_closed=False)
        connection = mock.Mock(is_open=True)
        connection.channel.return_value = self.channel
        patcher = mock.patch("payments.rabbitmq.pika.BlockingConnection", return_value=connection)
        self.connect = patcher.start()
        self.addCleanup(patcher.stop)
        self.publisher = PikaEventPublisher(parameters_factory=lambda: None)
        self.event = build_event("PaymentSucceeded", {"paymentId": "p"})

    def test_publishes_persistent_json_with_confirms_on_default_exchange(self):
        self.publisher.publish("reservation.payment_succeeded", self.event)
        self.channel.confirm_delivery.assert_called_once()
        kwargs = self.channel.basic_publish.call_args.kwargs
        self.assertEqual((kwargs["exchange"], kwargs["routing_key"], kwargs["mandatory"]),
                         ("", "reservation.payment_succeeded", True))
        props = kwargs["properties"]
        self.assertEqual((props.content_type, props.delivery_mode, props.message_id, props.type),
                         ("application/json", pika.DeliveryMode.Persistent.value, self.event["eventId"],
                          "PaymentSucceeded"))
        self.assertEqual(json.loads(kwargs["body"])["eventId"], self.event["eventId"])

    def test_connection_is_reused(self):
        self.publisher.publish("q", self.event)
        self.publisher.publish("q", self.event)
        self.connect.assert_called_once()

    def test_failures_become_503(self):
        for error in (pika_exc.UnroutableError([]), pika_exc.NackError([]), pika_exc.AMQPConnectionError("down")):
            self.channel.basic_publish.side_effect = error
            with self.subTest(error=type(error).__name__), self.assertRaises(EventPublishError) as ctx:
                self.publisher.publish("q", self.event)
            self.assertEqual(ctx.exception.status_code, 503)

    def test_broker_down_at_connect_is_503(self):
        self.connect.side_effect = pika_exc.AMQPConnectionError("refused")
        with self.assertRaises(EventPublishError):
            self.publisher.publish("q", self.event)

    def test_lost_connection_is_retried_once(self):
        self.channel.basic_publish.side_effect = [pika_exc.StreamLostError("lost"), None]
        self.publisher.publish("q", self.event)
        self.assertEqual(self.connect.call_count, 2)
