"""Consommateur RabbitMQ de la file ml.reservation_completed (aio-pika).

Règles de l'équipe (docs/conventions.md), comme le consommateur de payment-service :
- la file est déclarée par le consommateur (durable), avec sa file d'échecs <file>.dlq ;
- ack manuel, prefetch 1 (un message à la fois) ;
- message inutilisable -> directement en .dlq (réessayer ne servirait à rien) ;
- autre erreur (ex. base injoignable) : 1re fois -> remis en file ; déjà remis -> .dlq ;
- idempotent : un eventId déjà traité est ignoré (voir completed_reservations.py).
"""
import asyncio
import logging

import aio_pika
from aio_pika.abc import AbstractIncomingMessage

from app import completed_reservations
from app.database import SessionFactory
from app.settings import settings

QUEUE_NAME = settings.rabbitmq_queue                 # ml.reservation_completed (Config Server : ml.rabbitmq.queue)
DEAD_LETTER_QUEUE_NAME = f"{QUEUE_NAME}.dlq"
logger = logging.getLogger("ml-service")

_connection: aio_pika.abc.AbstractRobustConnection | None = None


def store_message(message_body: bytes) -> str:
    """Travail en base (synchrone) : « stored » ou « duplicate »."""
    with SessionFactory() as session:
        saved = completed_reservations.save_completed_reservation(session, message_body)
    return "stored" if saved is not None else "duplicate"


async def handle_message(message: AbstractIncomingMessage) -> str:
    """Traite un message et décide : ack, remise en file ou .dlq. Renvoie la décision (utile aux tests)."""
    try:
        # to_thread : le travail en base ne bloque pas le serveur web pendant ce temps
        outcome = await asyncio.to_thread(store_message, message.body)
    except completed_reservations.InvalidEventError as error:
        logger.error("[%s] message inutilisable, envoyé en %s : %s", QUEUE_NAME, DEAD_LETTER_QUEUE_NAME, error)
        await message.nack(requeue=False)        # requeue=False -> RabbitMQ le range dans la .dlq
        return "dlq"
    except Exception:
        if message.redelivered:
            logger.exception("[%s] nouvel échec après remise en file, envoyé en %s", QUEUE_NAME, DEAD_LETTER_QUEUE_NAME)
            await message.nack(requeue=False)
            return "dlq"
        logger.exception("[%s] échec, message remis en file une fois", QUEUE_NAME)
        await message.nack(requeue=True)
        return "requeued"

    await message.ack()                          # ack APRÈS l'enregistrement : aucun message perdu
    logger.info("[%s] réservation terminée %s", QUEUE_NAME,
                "enregistrée" if outcome == "stored" else "déjà traitée (eventId connu), ignorée")
    return outcome


async def declare_queues(channel: aio_pika.abc.AbstractChannel) -> aio_pika.abc.AbstractQueue:
    """File durable + sa file d'échecs : un message rejeté sans remise part dans la .dlq."""
    await channel.declare_queue(DEAD_LETTER_QUEUE_NAME, durable=True)
    return await channel.declare_queue(QUEUE_NAME, durable=True, arguments={
        "x-dead-letter-exchange": "",                       # échange par défaut
        "x-dead-letter-routing-key": DEAD_LETTER_QUEUE_NAME,
    })


async def start_consumer() -> None:
    """Appelé au démarrage du service. Si RabbitMQ est éteint, le service démarre quand même."""
    global _connection
    if not settings.rabbitmq_enabled:
        logger.info("Consommateur RabbitMQ désactivé (RABBITMQ_ENABLED=false)")
        return
    try:
        _connection = await aio_pika.connect_robust(settings.rabbitmq_url)   # se reconnecte tout seul ensuite
        channel = await _connection.channel()
        await channel.set_qos(prefetch_count=1)
        queue = await declare_queues(channel)
        await queue.consume(handle_message, no_ack=False)
        logger.info("Consommation de %s (prefetch 1, ack manuel)", QUEUE_NAME)
    except Exception as error:
        logger.warning("RabbitMQ injoignable (%s) : le service démarre sans consommateur", error)


async def stop_consumer() -> None:
    if _connection is not None:
        await _connection.close()
