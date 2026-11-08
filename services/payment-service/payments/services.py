"""
Business rules of payment-service. Views only parse/serialize; everything that
decides something lives here so it can be tested and reused by the RabbitMQ consumer.

Transaction pattern used for every state change:
    1. read-only checks + remote calls (reservation-service) OUTSIDE any transaction,
       so no database lock is held during an HTTP call;
    2. with transaction.atomic(): re-check under lock, write, issue invoice,
       and publish the event LAST;
    3. any exception inside the block (DB error, RabbitMQ down -> EventPublishError 503)
       rolls everything back, including the Idempotency-Key, so the client can retry.
"""
from __future__ import annotations

import logging
import uuid
from dataclasses import dataclass, field
from decimal import Decimal, InvalidOperation

from django.db import IntegrityError, transaction
from django.utils import timezone

from .auth import Principal
from .clients.reservations import Reservation, ReservationStatus, get_reservation_client
from .events import QUEUE_PAYMENT_SUCCEEDED, get_event_publisher, payment_succeeded_event
from .exceptions import (
    BadRequestError,
    BusinessValidationError,
    ConflictError,
    ForbiddenError,
    NotFoundError,
)
from .models import (
    ExtraFeeType,
    Invoice,
    InvoiceLine,
    Payment,
    PaymentMethod,
    PaymentStatus,
    PaymentType,
    ProcessedEvent,
)
from .numbering import next_invoice_number

logger = logging.getLogger(__name__)

IDEMPOTENCY_HEADER = "Idempotency-Key"
MAX_IDEMPOTENCY_KEY_LENGTH = 128
CLOSED_RESERVATION_STATUSES = {ReservationStatus.CANCELLED, ReservationStatus.COMPLETED}
FEE_LABELS = {
    ExtraFeeType.LATE: "Retard de restitution",
    ExtraFeeType.FUEL: "Carburant",
    ExtraFeeType.MILEAGE: "Kilométrage supplémentaire",
    ExtraFeeType.DAMAGE: "Dommages",
}


# --- Helpers -----------------------------------------------------------------

def _new_ref(prefix: str) -> str:
    return f"{prefix}-{uuid.uuid4().hex[:12].upper()}"


def simulate_card_payment(card_token: str) -> tuple[bool, str | None, str | None]:
    """
    Simulated payment provider. The card token is used only here and never stored.
    Returns (accepted, transaction_ref, failure_reason).
    """
    if card_token.startswith("fail_"):
        return False, None, "Carte refusée par la banque (simulation)"
    return True, _new_ref("SIM"), None


def check_idempotency_key(key: str | None) -> str:
    """400 if missing/too long, 409 if already used (even for an identical request)."""
    if not key or not key.strip():
        raise BadRequestError(
            f"L'en-tête {IDEMPOTENCY_HEADER} est obligatoire",
            [{"field": IDEMPOTENCY_HEADER, "message": "En-tête obligatoire (UUID conseillé)"}],
        )
    key = key.strip()
    if len(key) > MAX_IDEMPOTENCY_KEY_LENGTH:
        raise BadRequestError(
            f"{IDEMPOTENCY_HEADER} trop long",
            [{"field": IDEMPOTENCY_HEADER, "message": f"{MAX_IDEMPOTENCY_KEY_LENGTH} caractères maximum"}],
        )
    if Payment.objects.filter(idempotency_key=key).exists():
        raise _idempotency_conflict()
    return key


def _idempotency_conflict() -> ConflictError:
    return ConflictError(
        f"Cette {IDEMPOTENCY_HEADER} a déjà été utilisée ; générez une nouvelle clé pour un nouveau paiement",
        [{"field": IDEMPOTENCY_HEADER, "message": "Clé déjà utilisée"}],
    )


def _conflict_from_integrity_error(exc: IntegrityError) -> ConflictError:
    """
    Second line of defence: two concurrent requests both pass the read checks,
    the database constraint stops the second one -> translated to 409.
    """
    text = str(exc)
    if "idempotency_key" in text:
        return _idempotency_conflict()
    if "uniq_succeeded_rental_or_deposit_per_reservation" in text:
        return ConflictError("Cette réservation est déjà payée pour ce type de paiement")
    if "uniq_refund_per_original_payment" in text:
        return ConflictError("Ce paiement a déjà été remboursé")
    raise exc


def _expected_amount(reservation: Reservation, payment_type: str) -> Decimal:
    if payment_type == PaymentType.RENTAL:
        return reservation.total_price
    if payment_type == PaymentType.DEPOSIT:
        return reservation.deposit_amount
    raise ValueError(payment_type)


def _ensure_reservation_open(reservation: Reservation) -> None:
    if reservation.status in CLOSED_RESERVATION_STATUSES:
        raise ConflictError(
            f"La réservation est {reservation.status} : aucun paiement n'est possible",
            [{"field": "reservationId", "message": f"Statut de la réservation : {reservation.status}"}],
        )


def _ensure_not_already_paid(reservation_id, payment_type: str) -> None:
    existing = Payment.objects.filter(
        reservation_id=reservation_id, type=payment_type,
        status__in=[PaymentStatus.SUCCEEDED, PaymentStatus.PENDING],
    ).values_list("status", flat=True).first()
    if existing == PaymentStatus.SUCCEEDED:
        raise ConflictError(f"Cette réservation est déjà payée ({payment_type})")
    if existing == PaymentStatus.PENDING:
        raise ConflictError(f"Un paiement {payment_type} est déjà en attente pour cette réservation")


def _issue_invoice(reservation_id, user_id, lines: list[dict]) -> Invoice:
    """Must run inside transaction.atomic() (invoice number lock)."""
    invoice = Invoice.objects.create(
        number=next_invoice_number(),
        reservation_id=reservation_id,
        user_id=user_id,
        total=sum((Decimal(line["amount"]) for line in lines), Decimal("0")),
        issued_at=timezone.now(),
    )
    InvoiceLine.objects.bulk_create(InvoiceLine(invoice=invoice, **line) for line in lines)
    return invoice


def _on_payment_succeeded(payment: Payment, reservation: Reservation | None) -> None:
    """
    Side effects of a successful payment, inside the payment's transaction:
    - RENTAL -> invoice;
    - every successful payment -> PaymentSucceeded on reservation.payment_succeeded
      (reservation-service only confirms on type RENTAL).
    The publish is the LAST statement: if RabbitMQ is down it raises EventPublishError
    (503) and transaction.atomic rolls back the payment and the invoice.
    """
    if payment.type == PaymentType.RENTAL:
        label = (f"Location du {reservation.start_date.isoformat()} au {reservation.end_date.isoformat()}"
                 if reservation else "Location du véhicule")
        _issue_invoice(payment.reservation_id, payment.user_id, [
            dict(label=label, type=PaymentType.RENTAL, amount=payment.amount, payment=payment),
        ])
    get_event_publisher().publish(QUEUE_PAYMENT_SUCCEEDED, payment_succeeded_event(payment))


# --- Visibility --------------------------------------------------------------

def payments_visible_to(principal: Principal):
    qs = Payment.objects.all()
    return qs if principal.is_staff_like else qs.filter(user_id=principal.user_id)


def invoices_visible_to(principal: Principal):
    qs = Invoice.objects.prefetch_related("lines")
    return qs if principal.is_staff_like else qs.filter(user_id=principal.user_id)


def get_payment_for(principal: Principal, payment_id) -> Payment:
    try:
        payment = Payment.objects.get(pk=payment_id)
    except Payment.DoesNotExist:
        raise NotFoundError(f"Paiement {payment_id} introuvable")
    if not principal.is_staff_like and payment.user_id != principal.user_id:
        raise ForbiddenError("Ce paiement appartient à un autre utilisateur")
    return payment


# --- Use cases: API ----------------------------------------------------------

def create_payment(
    principal: Principal, *, reservation_id, type: str, method: str, amount: Decimal,
    card_token: str | None, idempotency_key: str | None,
) -> Payment:
    """
    POST /api/payments. Order of checks (first failure wins):
    400 Idempotency-Key missing -> 409 key reused -> 403 CASH by a client
    -> 404/503/502 reservation -> 403 not the reservation owner
    -> 409 reservation CANCELLED/COMPLETED -> 409 already paid/pending -> 422 wrong amount.
    A refused card is NOT an error: the payment is stored FAILED and returned with 201.
    """
    key = check_idempotency_key(idempotency_key)
    if method == PaymentMethod.CASH and not principal.is_staff_like:
        raise ForbiddenError("Seul un agent peut enregistrer un paiement en espèces")

    reservation = get_reservation_client().get_reservation(reservation_id)
    if not principal.is_staff_like and reservation.user_id != principal.user_id:
        raise ForbiddenError("Cette réservation appartient à un autre utilisateur")
    _ensure_reservation_open(reservation)
    _ensure_not_already_paid(reservation.id, type)
    expected = _expected_amount(reservation, type)
    if amount != expected:
        raise BusinessValidationError(
            "Le montant ne correspond pas au prix attendu",
            [{"field": "amount", "message": f"Montant attendu : {expected:.3f} TND, reçu : {amount:.3f} TND"}],
        )

    failure_reason = None
    if method == PaymentMethod.CARD:
        accepted, ref, failure_reason = simulate_card_payment(card_token or "")
        status = PaymentStatus.SUCCEEDED if accepted else PaymentStatus.FAILED
    elif method == PaymentMethod.CASH:
        status, ref = PaymentStatus.SUCCEEDED, _new_ref("CASH")
    else:  # TRANSFER: waits for an ADMIN to confirm reception (PATCH status=SUCCEEDED)
        status, ref = PaymentStatus.PENDING, None

    try:
        with transaction.atomic():
            payment = Payment.objects.create(
                reservation_id=reservation.id, user_id=reservation.user_id, amount=amount,
                method=method, type=type, status=status, transaction_ref=ref,
                failure_reason=failure_reason, idempotency_key=key,
            )
            if status == PaymentStatus.SUCCEEDED:
                _on_payment_succeeded(payment, reservation)
    except IntegrityError as exc:
        raise _conflict_from_integrity_error(exc)
    return payment


def pay_pending_payment(
    principal: Principal, payment_id, *, method: str, card_token: str | None, idempotency_key: str | None,
) -> Payment:
    """
    POST /api/payments/{id}/pay: settle a PENDING payment (extra fee after return,
    or a pending transfer the client finally pays by card).
    A refused card -> 422 and the payment stays PENDING (the client can retry).
    """
    key = check_idempotency_key(idempotency_key)
    payment = get_payment_for(principal, payment_id)
    if payment.status != PaymentStatus.PENDING:
        raise ConflictError(f"Seul un paiement PENDING peut être réglé (statut actuel : {payment.status})")
    if method == PaymentMethod.CASH and not principal.is_staff_like:
        raise ForbiddenError("Seul un agent peut enregistrer un paiement en espèces")

    reservation = None
    if payment.type in (PaymentType.RENTAL, PaymentType.DEPOSIT):
        reservation = get_reservation_client().get_reservation(payment.reservation_id)
        _ensure_reservation_open(reservation)

    if method == PaymentMethod.CARD:
        accepted, ref, failure_reason = simulate_card_payment(card_token or "")
        if not accepted:
            raise BusinessValidationError(failure_reason, [{"field": "cardToken", "message": "Carte refusée"}])
    else:
        ref = _new_ref("CASH")

    try:
        with transaction.atomic():
            locked = Payment.objects.select_for_update().get(pk=payment.pk)
            if locked.status != PaymentStatus.PENDING:  # paid concurrently
                raise ConflictError("Ce paiement vient d'être modifié par une autre requête")
            locked.method, locked.status, locked.transaction_ref = method, PaymentStatus.SUCCEEDED, ref
            locked.idempotency_key, locked.failure_reason = key, None
            locked.save()
            _on_payment_succeeded(locked, reservation)
    except IntegrityError as exc:
        raise _conflict_from_integrity_error(exc)
    return locked


def update_payment(principal: Principal, payment_id, changes: dict) -> Payment:
    """
    PATCH /api/payments/{id} (ADMIN): only a PENDING payment can be modified.
    Typical use: confirm a TRANSFER (status=SUCCEEDED, transactionRef=bank ref) or reject it (FAILED).
    """
    payment = get_payment_for(principal, payment_id)
    if payment.status != PaymentStatus.PENDING:
        raise ConflictError(f"Seul un paiement PENDING peut être modifié (statut actuel : {payment.status})")

    new_status = changes.get("status")
    reservation = None
    if new_status == PaymentStatus.SUCCEEDED and payment.type in (PaymentType.RENTAL, PaymentType.DEPOSIT):
        reservation = get_reservation_client().get_reservation(payment.reservation_id)
        _ensure_reservation_open(reservation)

    try:
        with transaction.atomic():
            locked = Payment.objects.select_for_update().get(pk=payment.pk)
            if locked.status != PaymentStatus.PENDING:
                raise ConflictError("Ce paiement vient d'être modifié par une autre requête")
            for name in ("method", "transaction_ref", "failure_reason"):
                if name in changes:
                    setattr(locked, name, changes[name])
            if new_status:
                if new_status == PaymentStatus.SUCCEEDED and not locked.method:
                    raise BusinessValidationError(
                        "Le moyen de paiement est requis pour valider le paiement",
                        [{"field": "method", "message": "Obligatoire quand status=SUCCEEDED"}],
                    )
                locked.status = new_status
            locked.save()
            if locked.status == PaymentStatus.SUCCEEDED:
                _on_payment_succeeded(locked, reservation)
    except IntegrityError as exc:
        raise _conflict_from_integrity_error(exc)
    return locked


def delete_payment(principal: Principal, payment_id) -> None:
    """DELETE /api/payments/{id} (ADMIN): only PENDING or FAILED, and not on an invoice."""
    payment = get_payment_for(principal, payment_id)
    if payment.status not in (PaymentStatus.PENDING, PaymentStatus.FAILED):
        raise ConflictError(f"Seul un paiement PENDING ou FAILED peut être supprimé (statut : {payment.status})")
    if payment.invoice_lines.exists():
        raise ConflictError("Ce paiement figure sur une facture et ne peut pas être supprimé")
    payment.delete()


# --- Use cases: events consumed from RabbitMQ (wired in step 3) ---------------

class InvalidEvent(ValueError):
    """Malformed event data: the consumer rejects it (-> .dlq)."""


def _uuid(data: dict, key: str) -> uuid.UUID:
    try:
        return uuid.UUID(str(data[key]))
    except (KeyError, ValueError, TypeError):
        raise InvalidEvent(f"data.{key} manquant ou invalide")


def _amount(value, name: str, allow_zero=False) -> Decimal:
    try:
        amount = Decimal(str(value)).quantize(Decimal("0.001"))
    except (InvalidOperation, TypeError, ValueError):
        raise InvalidEvent(f"{name} invalide : {value!r}")
    if amount < 0 or (amount == 0 and not allow_zero):
        raise InvalidEvent(f"{name} doit être positif : {value!r}")
    return amount


def _mark_processed(event_id, event_type: str, queue: str) -> bool:
    """
    Consumer idempotency. Inside the caller's transaction: True if this eventId is new,
    False if it was already processed. Because the row is written in the SAME transaction
    as the business changes, a crash before commit leaves no trace and the redelivered
    message is processed again; after commit, a redelivery is ignored.
    """
    try:
        with transaction.atomic():  # savepoint, so the IntegrityError does not break the outer transaction
            ProcessedEvent.objects.create(event_id=event_id, type=event_type, queue=queue)
        return True
    except IntegrityError:
        return False


@dataclass
class EventResult:
    duplicate: bool = False
    refunds: list[Payment] = field(default_factory=list)
    cancelled_pending: list[Payment] = field(default_factory=list)
    fees: list[Payment] = field(default_factory=list)
    invoice: Invoice | None = None


def handle_reservation_cancelled(event_id, data: dict, queue="payment.reservation_cancelled") -> EventResult:
    """
    ReservationCancelled {reservationId, userId, cancelledAt, reason?}:
    one REFUND per SUCCEEDED RENTAL/DEPOSIT payment (the original becomes REFUNDED);
    PENDING payments of the reservation become FAILED.
    """
    reservation_id = _uuid(data, "reservationId")
    result = EventResult()
    with transaction.atomic():
        if not _mark_processed(event_id, "ReservationCancelled", queue):
            result.duplicate = True
            return result
        payments = Payment.objects.select_for_update().filter(reservation_id=reservation_id)
        for original in payments.filter(
            status=PaymentStatus.SUCCEEDED, type__in=[PaymentType.RENTAL, PaymentType.DEPOSIT]
        ):
            result.refunds.append(Payment.objects.create(
                reservation_id=reservation_id, user_id=original.user_id, amount=original.amount,
                method=original.method, type=PaymentType.REFUND, status=PaymentStatus.SUCCEEDED,
                transaction_ref=_new_ref("REF"), original_payment=original,
            ))
            original.status = PaymentStatus.REFUNDED
            original.save(update_fields=["status", "updated_at"])
        for pending in payments.filter(status=PaymentStatus.PENDING):
            pending.status = PaymentStatus.FAILED
            pending.failure_reason = "Réservation annulée"
            pending.save(update_fields=["status", "failure_reason", "updated_at"])
            result.cancelled_pending.append(pending)
    return result


def handle_return_settled(event_id, data: dict, queue="payment.return_settled") -> EventResult:
    """
    ReturnSettled {contractId, reservationId, userId, extraFees:[{type, amount}], depositToRelease}:
    - depositToRelease > 0 -> REFUND of that amount, the DEPOSIT becomes REFUNDED;
    - each fee -> EXTRA_FEE payment PENDING (the client pays it later);
    - one invoice for the fees, if any.
    """
    reservation_id = _uuid(data, "reservationId")
    user_id = _uuid(data, "userId")
    to_release = _amount(data.get("depositToRelease", 0), "depositToRelease", allow_zero=True)
    fees = []
    for i, fee in enumerate(data.get("extraFees") or []):
        if not isinstance(fee, dict) or fee.get("type") not in ExtraFeeType.values:
            raise InvalidEvent(f"extraFees[{i}].type invalide (attendu : {', '.join(ExtraFeeType.values)})")
        fees.append((fee["type"], _amount(fee.get("amount"), f"extraFees[{i}].amount")))

    result = EventResult()
    with transaction.atomic():
        if not _mark_processed(event_id, "ReturnSettled", queue):
            result.duplicate = True
            return result
        if to_release > 0:
            deposit = (Payment.objects.select_for_update()
                       .filter(reservation_id=reservation_id, type=PaymentType.DEPOSIT, status=PaymentStatus.SUCCEEDED)
                       .first())
            if deposit is None:
                raise InvalidEvent("Aucune caution payée à rendre pour cette réservation")
            if to_release > deposit.amount:
                raise InvalidEvent(f"depositToRelease ({to_release}) dépasse la caution payée ({deposit.amount})")
            result.refunds.append(Payment.objects.create(
                reservation_id=reservation_id, user_id=deposit.user_id, amount=to_release,
                method=deposit.method, type=PaymentType.REFUND, status=PaymentStatus.SUCCEEDED,
                transaction_ref=_new_ref("REF"), original_payment=deposit,
            ))
            deposit.status = PaymentStatus.REFUNDED
            deposit.save(update_fields=["status", "updated_at"])
        for fee_type, amount in fees:
            result.fees.append(Payment.objects.create(
                reservation_id=reservation_id, user_id=user_id, amount=amount, method=None,
                type=PaymentType.EXTRA_FEE, fee_type=fee_type, status=PaymentStatus.PENDING,
            ))
        if result.fees:
            result.invoice = _issue_invoice(reservation_id, user_id, [
                dict(label=f"Frais de retour : {FEE_LABELS[p.fee_type]}", type=PaymentType.EXTRA_FEE,
                     fee_type=p.fee_type, amount=p.amount, payment=p)
                for p in result.fees
            ])
    return result
