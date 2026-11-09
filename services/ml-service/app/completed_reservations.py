"""Réservations terminées (événement ReservationCompleted) -> lignes d'entraînement en base.

Logique métier SANS RabbitMQ (le transport est dans rabbitmq_consumer.py) : facile à tester.
Format du message : docs/events/ReservationCompleted.json
"""
import json
import uuid
from datetime import date, datetime, timezone
from decimal import Decimal

import pandas as pd
from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app import price_predictor
from app.db_tables import CompletedReservation
from app.error_handlers import ServiceUnavailableError
from app.rental_catalog import AGENCIES, CATEGORIES

EXPECTED_EVENT_TYPE = "ReservationCompleted"
REQUIRED_DATA_FIELDS = ("reservationId", "categoryId", "agencyId", "startDate", "endDate", "totalPrice", "bookedAt")


class InvalidEventError(Exception):
    """Message inutilisable (JSON illisible, champ manquant, valeur incohérente) : réessayer ne servira à rien."""


def parse_event(message_body: bytes) -> dict:
    """Lit et valide l'événement. Lève InvalidEventError s'il est inutilisable."""
    try:
        event = json.loads(message_body)
        if event.get("type") != EXPECTED_EVENT_TYPE:
            raise InvalidEventError(f"type attendu {EXPECTED_EVENT_TYPE}, reçu {event.get('type')!r}")
        data = event["data"]
        missing_fields = [field for field in REQUIRED_DATA_FIELDS if field not in data]
        if missing_fields:
            raise InvalidEventError(f"champs manquants dans data : {', '.join(missing_fields)}")
        parsed = {
            "event_id": uuid.UUID(event["eventId"]),
            "reservation_id": uuid.UUID(data["reservationId"]),
            "category_id": uuid.UUID(data["categoryId"]),
            "agency_id": uuid.UUID(data["agencyId"]),
            "start_date": date.fromisoformat(data["startDate"]),
            "end_date": date.fromisoformat(data["endDate"]),
            "booked_at": datetime.fromisoformat(data["bookedAt"]),
            "total_price": Decimal(str(data["totalPrice"])),
        }
    except InvalidEventError:
        raise
    except (ValueError, KeyError, TypeError, AttributeError, ArithmeticError) as error:
        raise InvalidEventError(f"message illisible : {error!r}") from None

    if str(parsed["category_id"]) not in CATEGORIES:
        raise InvalidEventError(f"catégorie inconnue : {parsed['category_id']}")
    if str(parsed["agency_id"]) not in AGENCIES:
        raise InvalidEventError(f"agence inconnue : {parsed['agency_id']}")
    if (parsed["end_date"] - parsed["start_date"]).days < 1:
        raise InvalidEventError("endDate doit être après startDate")
    if parsed["total_price"] <= 0:
        raise InvalidEventError("totalPrice doit être positif")
    return parsed


def estimate_occupancy_rate(session: Session, agency_id: uuid.UUID, start_date: date) -> float | None:
    """Occupation estimée comme pour une prédiction (moyenne agence x saison du modèle actif).
    None s'il n'y a pas de modèle actif : la ligne est gardée mais ignorée à l'entraînement."""
    try:
        active_version = price_predictor.find_active_model_version(session)
        model_bundle = price_predictor.get_model_bundle(active_version.version)
    except ServiceUnavailableError:
        return None
    return price_predictor.estimate_occupancy_rate(model_bundle, str(agency_id), start_date)


def save_completed_reservation(session: Session, message_body: bytes) -> CompletedReservation | None:
    """Enregistre l'événement comme ligne d'entraînement. Renvoie None si cet eventId est déjà connu."""
    event = parse_event(message_body)
    if session.scalar(select(CompletedReservation.id).where(CompletedReservation.event_id == event["event_id"])):
        return None                                                     # déjà traité : idempotence

    duration_days = (event["end_date"] - event["start_date"]).days
    occupancy_rate = estimate_occupancy_rate(session, event["agency_id"], event["start_date"])
    completed_reservation = CompletedReservation(
        **event,
        daily_price=(event["total_price"] / duration_days).quantize(Decimal("0.001")),
        occupancy_rate=occupancy_rate,
        occupancy_estimated=True,
    )
    session.add(completed_reservation)
    try:
        session.commit()
    except IntegrityError:            # le même eventId enregistré en parallèle : on l'ignore aussi
        session.rollback()
        return None
    return completed_reservation


def load_training_rows(session: Session) -> pd.DataFrame:
    """Réservations terminées au format du CSV synthétique (colonnes camelCase), pour l'entraînement.
    Les lignes sans occupation (aucun modèle actif à leur arrivée) sont ignorées."""
    rows = session.scalars(select(CompletedReservation).where(CompletedReservation.occupancy_rate.is_not(None))).all()
    return pd.DataFrame([{
        "categoryId": str(row.category_id),
        "agencyId": str(row.agency_id),
        "bookedAt": row.booked_at.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "startDate": row.start_date.isoformat(),
        "endDate": row.end_date.isoformat(),
        "occupancyRate": row.occupancy_rate,
        "dailyPrice": float(row.daily_price),
        "totalPrice": float(row.total_price),
    } for row in rows], columns=["categoryId", "agencyId", "bookedAt", "startDate", "endDate",
                                 "occupancyRate", "dailyPrice", "totalPrice"])
