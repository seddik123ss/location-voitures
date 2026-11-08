import json
import uuid
from pathlib import Path

from django.conf import settings

from payments.demo_data import ADMIN1, AGENT1, CLIENT1, CLIENT2
from payments.events import EventPublishError

EVENTS_DIR = Path(settings.BASE_DIR).parent.parent / "docs" / "events"


def load_event(name: str) -> dict:
    """Official example message from docs/events/<name>.json."""
    return json.loads((EVENTS_DIR / f"{name}.json").read_text(encoding="utf-8"))


class RecordingPublisher:
    """Test double for the RabbitMQ publisher; fail=True simulates RabbitMQ down."""

    def __init__(self, fail: bool = False):
        self.fail = fail
        self.published: list[tuple[str, dict]] = []

    def publish(self, queue, event):
        if self.fail:
            raise EventPublishError()
        self.published.append((queue, event))


def as_user(user_id, roles: str, idempotency_key=True) -> dict:
    headers = {"X-User-Id": str(user_id), "X-User-Roles": roles}
    if idempotency_key:
        headers["Idempotency-Key"] = (
            idempotency_key if isinstance(idempotency_key, str) else str(uuid.uuid4())
        )
    return headers


def client1(**kw):
    return as_user(CLIENT1, "CLIENT", **kw)


def client2(**kw):
    return as_user(CLIENT2, "CLIENT", **kw)


def agent(**kw):
    return as_user(AGENT1, "AGENT", **kw)


def admin(**kw):
    return as_user(ADMIN1, "ADMIN", **kw)


INTERNAL = {"X-User-Roles": "INTERNAL"}
