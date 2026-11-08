"""
Client for reservation-service (Eureka name RENTAL-RESERVATION).

    GET /internal/reservations/{id}
    -> {id, userId, vehicleId, startDate, endDate, status, totalPrice, depositAmount, currency}

The rest of the code depends only on the ReservationClient interface and on
get_reservation_client(), so the simulated client can be swapped for the real one
with one environment variable: RESERVATION_CLIENT=fake | http.

Error translation (docs/conventions.md):
    remote 404                                  -> ReservationNotFound      (404)
    not in Eureka / connection error / timeout
    / remote 503 or 504                         -> ReservationServiceUnavailable (503)
    invalid JSON, missing fields, other status  -> ReservationBadResponse   (502)
"""
from __future__ import annotations

import logging
import uuid
from dataclasses import dataclass
from datetime import date
from decimal import Decimal, InvalidOperation
from typing import Callable, Protocol

import requests
from django.conf import settings

from payments.correlation import HEADER as CORRELATION_HEADER
from payments.correlation import get_correlation_id
from payments.exceptions import BadGatewayError, NotFoundError, ServiceUnavailableError

from .discovery import ServiceNotFound, resolve_service_url

logger = logging.getLogger(__name__)

RESERVATION_APP = "RENTAL-RESERVATION"


class ReservationStatus:
    PENDING = "PENDING"
    CONFIRMED = "CONFIRMED"
    ONGOING = "ONGOING"
    COMPLETED = "COMPLETED"
    CANCELLED = "CANCELLED"
    ALL = {PENDING, CONFIRMED, ONGOING, COMPLETED, CANCELLED}


@dataclass(frozen=True)
class Reservation:
    id: uuid.UUID
    user_id: uuid.UUID
    vehicle_id: uuid.UUID
    start_date: date
    end_date: date
    status: str
    total_price: Decimal
    deposit_amount: Decimal
    currency: str


# --- Errors ------------------------------------------------------------------

class ReservationNotFound(NotFoundError):
    default_message = "Réservation introuvable"

    def __init__(self, reservation_id):
        super().__init__(f"Réservation {reservation_id} introuvable")


class ReservationServiceUnavailable(ServiceUnavailableError):
    default_message = "Le service des réservations est indisponible"


class ReservationBadResponse(BadGatewayError):
    default_message = "Réponse illisible du service des réservations"


# --- Interface ---------------------------------------------------------------

class ReservationClient(Protocol):
    def get_reservation(self, reservation_id: uuid.UUID) -> Reservation:
        """Return the reservation or raise ReservationNotFound / ...Unavailable / ...BadResponse."""
        ...


def parse_reservation(payload) -> Reservation:
    """Validate the camelCase JSON of reservation-service; anything unexpected -> 502."""
    try:
        status = payload["status"]
        if status not in ReservationStatus.ALL:
            raise ValueError(f"statut inconnu {status!r}")
        return Reservation(
            id=uuid.UUID(str(payload["id"])),
            user_id=uuid.UUID(str(payload["userId"])),
            vehicle_id=uuid.UUID(str(payload["vehicleId"])),
            start_date=date.fromisoformat(str(payload["startDate"])[:10]),
            end_date=date.fromisoformat(str(payload["endDate"])[:10]),
            status=status,
            total_price=Decimal(str(payload["totalPrice"])),
            deposit_amount=Decimal(str(payload["depositAmount"])),
            currency=str(payload.get("currency") or "TND"),
        )
    except (KeyError, TypeError, ValueError, InvalidOperation) as exc:
        logger.warning("Unreadable reservation payload: %s", exc)
        raise ReservationBadResponse(f"Réponse illisible du service des réservations ({exc})")


# --- Simulated client (RESERVATION_CLIENT=fake) -------------------------------

class FakeReservationClient:
    """
    In-memory reservations (payments/demo_data.py) so payment-service works before
    reservation-service exists. Two special ids simulate the remote failures:
        ...000000000503 -> 503 (service down)   ...000000000502 -> 502 (bad response)
    """
    UNAVAILABLE_ID = uuid.UUID("ffffffff-ffff-4fff-8fff-000000000503")
    BAD_RESPONSE_ID = uuid.UUID("ffffffff-ffff-4fff-8fff-000000000502")

    def __init__(self, reservations: dict | None = None):
        if reservations is None:
            from payments.demo_data import RESERVATIONS
            reservations = RESERVATIONS
        self._reservations = {
            uuid.UUID(str(rid)): self._build(uuid.UUID(str(rid)), data) for rid, data in reservations.items()
        }

    @staticmethod
    def _build(rid: uuid.UUID, d: dict) -> Reservation:
        return Reservation(
            id=rid, user_id=d["user_id"], vehicle_id=d["vehicle_id"],
            start_date=date.fromisoformat(str(d["start_date"])), end_date=date.fromisoformat(str(d["end_date"])),
            status=d["status"], total_price=Decimal(d["total_price"]),
            deposit_amount=Decimal(d["deposit_amount"]), currency=d.get("currency", "TND"),
        )

    def get_reservation(self, reservation_id: uuid.UUID) -> Reservation:
        reservation_id = uuid.UUID(str(reservation_id))
        if reservation_id == self.UNAVAILABLE_ID:
            raise ReservationServiceUnavailable()
        if reservation_id == self.BAD_RESPONSE_ID:
            raise ReservationBadResponse()
        try:
            return self._reservations[reservation_id]
        except KeyError:
            raise ReservationNotFound(reservation_id)


# --- Real client (RESERVATION_CLIENT=http) ----------------------------------

class HttpReservationClient:
    def __init__(
        self,
        resolve_base_url: Callable[[str], str] = resolve_service_url,
        token_provider: Callable[[], str] | None = None,
        session: requests.Session | None = None,
        timeout: float = 3.0,
    ):
        self._resolve = resolve_base_url
        self._token_provider = token_provider  # service-account token (role INTERNAL), step 3
        self._session = session or requests.Session()
        self._timeout = timeout

    def get_reservation(self, reservation_id: uuid.UUID) -> Reservation:
        try:
            base_url = self._resolve(RESERVATION_APP)
        except ServiceNotFound as exc:
            raise ReservationServiceUnavailable(str(exc))

        headers = {"Accept": "application/json"}
        if (cid := get_correlation_id()):
            headers[CORRELATION_HEADER] = cid
        if self._token_provider:
            headers["Authorization"] = f"Bearer {self._token_provider()}"

        url = f"{base_url.rstrip('/')}/internal/reservations/{reservation_id}"
        try:
            response = self._session.get(url, headers=headers, timeout=self._timeout)
        except (requests.ConnectionError, requests.Timeout) as exc:
            logger.warning("reservation-service unreachable (%s): %s", url, exc)
            raise ReservationServiceUnavailable()

        if response.status_code == 404:
            raise ReservationNotFound(reservation_id)
        if response.status_code in (503, 504):
            raise ReservationServiceUnavailable()
        if response.status_code != 200:
            raise ReservationBadResponse(
                f"Réponse inattendue du service des réservations (HTTP {response.status_code})"
            )
        try:
            payload = response.json()
        except ValueError:
            raise ReservationBadResponse("Le service des réservations a renvoyé un JSON invalide")
        return parse_reservation(payload)


# --- Factory -----------------------------------------------------------------

def get_reservation_client() -> ReservationClient:
    kind = getattr(settings, "RESERVATION_CLIENT", "fake").lower()
    if kind == "fake":
        return FakeReservationClient()
    if kind == "http":
        return HttpReservationClient()
    raise ValueError(f"RESERVATION_CLIENT inconnu : {kind!r} (attendu : fake | http)")
