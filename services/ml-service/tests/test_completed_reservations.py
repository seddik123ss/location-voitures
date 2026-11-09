"""Consommateur ml.reservation_completed : enregistrement, idempotence, messages invalides,
décision ack / remise en file / .dlq, et utilisation des lignes à l'entraînement (sans RabbitMQ)."""
import asyncio
import copy
import json

from app import model_training, rabbitmq_consumer
from app.completed_reservations import InvalidEventError, parse_event
from app.settings import SERVICE_DIR

EXAMPLE_EVENT = json.loads(
    (SERVICE_DIR.parent.parent / "docs" / "events" / "ReservationCompleted.json").read_text(encoding="utf-8"))


class FakeMessage:
    """Imite un message aio-pika : corps, drapeau « déjà remis en file », ack/nack enregistrés."""

    def __init__(self, event_or_bytes, redelivered: bool = False):
        self.body = event_or_bytes if isinstance(event_or_bytes, bytes) else json.dumps(event_or_bytes).encode()
        self.redelivered = redelivered
        self.decisions = []

    async def ack(self):
        self.decisions.append("ack")

    async def nack(self, requeue: bool = True):
        self.decisions.append(f"nack(requeue={requeue})")


def deliver(message: FakeMessage) -> str:
    return asyncio.run(rabbitmq_consumer.handle_message(message))


def event_with(**data_changes) -> dict:
    event = copy.deepcopy(EXAMPLE_EVENT)
    event["data"].update(data_changes)
    return event


def test_completed_reservation_becomes_training_row_with_daily_price(run_sql):
    message = FakeMessage(EXAMPLE_EVENT)            # SUV, Sousse, 01/10 -> 05/10, totalPrice 640.0

    assert deliver(message) == "stored"
    assert message.decisions == ["ack"]
    assert run_sql("SELECT daily_price FROM completed_reservations") == 160      # 640 / 4 jours
    # occupation estimée avec le tableau du modèle actif (Sousse, saison MOYENNE en octobre)
    assert run_sql("SELECT occupancy_rate FROM completed_reservations") == 0.649
    assert run_sql("SELECT occupancy_estimated FROM completed_reservations") is True


def test_same_event_id_is_stored_only_once(run_sql):
    first_delivery, second_delivery = FakeMessage(EXAMPLE_EVENT), FakeMessage(EXAMPLE_EVENT)

    assert deliver(first_delivery) == "stored"
    assert deliver(second_delivery) == "duplicate"
    assert second_delivery.decisions == ["ack"]       # déjà traité : retiré de la file, sans doublon
    assert run_sql("SELECT count(*) FROM completed_reservations") == 1


def test_unusable_events_are_rejected():
    missing_field = copy.deepcopy(EXAMPLE_EVENT)
    del missing_field["data"]["totalPrice"]
    unusable_messages = {
        "JSON illisible": b"{pas du json",
        "mauvais type": json.dumps({**EXAMPLE_EVENT, "type": "ReservationCancelled"}).encode(),
        "champ manquant": json.dumps(missing_field).encode(),
        "catégorie inconnue": json.dumps(event_with(categoryId="00000000-0000-4000-8000-000000000000")).encode(),
        "retour avant départ": json.dumps(event_with(endDate="2026-09-28")).encode(),
        "prix négatif": json.dumps(event_with(totalPrice=-5)).encode(),
    }
    for case, body in unusable_messages.items():
        try:
            parse_event(body)
        except InvalidEventError:
            continue
        raise AssertionError(f"message accepté à tort : {case}")


def test_unusable_message_goes_straight_to_dlq(run_sql):
    message = FakeMessage(b"{pas du json")

    assert deliver(message) == "dlq"
    assert message.decisions == ["nack(requeue=False)"]     # sans remise : RabbitMQ le range dans la .dlq
    assert run_sql("SELECT count(*) FROM completed_reservations") == 0


def test_transient_error_is_requeued_once_then_dead_lettered(monkeypatch):
    def database_down(message_body):
        raise ConnectionError("base injoignable")
    monkeypatch.setattr(rabbitmq_consumer, "store_message", database_down)

    first_delivery = FakeMessage(EXAMPLE_EVENT, redelivered=False)
    second_delivery = FakeMessage(EXAMPLE_EVENT, redelivered=True)

    assert deliver(first_delivery) == "requeued"
    assert first_delivery.decisions == ["nack(requeue=True)"]
    assert deliver(second_delivery) == "dlq"
    assert second_delivery.decisions == ["nack(requeue=False)"]


def test_training_uses_completed_reservations(client, monkeypatch):
    deliver(FakeMessage(EXAMPLE_EVENT))
    received_options = {}
    training_function = model_training.train_new_model_version

    def spy_training(version, **options):
        received_options.update(options)
        return training_function(version, **options)
    monkeypatch.setattr(model_training, "train_new_model_version", spy_training)

    response = client.post("/api/ml/trainings")

    assert response.status_code == 201
    real_reservations = received_options["extra_reservations"]
    assert len(real_reservations) == 1
    assert real_reservations.iloc[0]["dailyPrice"] == 160.0
