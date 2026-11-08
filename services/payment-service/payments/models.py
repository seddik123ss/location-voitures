"""
Data model of payment-service (PostgreSQL payment_db).

Rule "no foreign key between databases": reservationId / userId / contractId are
plain UUIDs pointing to objects owned by other services, never relations.
Relations only exist between tables of this service (refund -> original payment,
invoice -> lines -> payment).
"""
import uuid
from decimal import Decimal

from django.core.validators import MinValueValidator
from django.db import models
from django.db.models import Q


class PaymentMethod(models.TextChoices):
    CARD = "CARD"
    CASH = "CASH"
    TRANSFER = "TRANSFER"


class PaymentType(models.TextChoices):
    RENTAL = "RENTAL"
    DEPOSIT = "DEPOSIT"
    EXTRA_FEE = "EXTRA_FEE"
    REFUND = "REFUND"


class PaymentStatus(models.TextChoices):
    PENDING = "PENDING"
    SUCCEEDED = "SUCCEEDED"
    FAILED = "FAILED"
    REFUNDED = "REFUNDED"


class ExtraFeeType(models.TextChoices):
    """Fee categories sent by contract-service in ReturnSettled."""
    LATE = "LATE"
    FUEL = "FUEL"
    MILEAGE = "MILEAGE"
    DAMAGE = "DAMAGE"


AMOUNT_FIELD = dict(max_digits=12, decimal_places=3)  # TND, 3 decimals (millimes)


class Payment(models.Model):
    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    reservation_id = models.UUIDField(db_index=True)
    user_id = models.UUIDField(db_index=True)
    amount = models.DecimalField(**AMOUNT_FIELD, validators=[MinValueValidator(Decimal("0.001"))])
    currency = models.CharField(max_length=3, default="TND")
    # NULL while PENDING (e.g. an EXTRA_FEE created at vehicle return): the client
    # chooses the method when paying it.
    method = models.CharField(max_length=10, choices=PaymentMethod.choices, null=True, blank=True)
    type = models.CharField(max_length=10, choices=PaymentType.choices)
    status = models.CharField(max_length=10, choices=PaymentStatus.choices, default=PaymentStatus.PENDING)
    # Simulated PSP reference (e.g. SIM-3F2A...), or agency receipt for CASH. Never card data.
    transaction_ref = models.CharField(max_length=64, null=True, blank=True)
    # Client-provided Idempotency-Key header. NULL for payments created internally
    # (refunds, extra fees from events). PostgreSQL allows several NULLs in a unique column.
    idempotency_key = models.CharField(max_length=128, null=True, blank=True, unique=True)
    # For REFUND: the payment being refunded. For EXTRA_FEE: the fee category.
    original_payment = models.ForeignKey(
        "self", null=True, blank=True, on_delete=models.PROTECT, related_name="refunds"
    )
    fee_type = models.CharField(max_length=10, choices=ExtraFeeType.choices, null=True, blank=True)
    failure_reason = models.CharField(max_length=255, null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        ordering = ["-created_at"]
        indexes = [models.Index(fields=["reservation_id", "type", "status"])]
        constraints = [
            models.CheckConstraint(condition=Q(amount__gt=0), name="payment_amount_positive"),
            models.CheckConstraint(condition=Q(currency="TND"), name="payment_currency_tnd"),
            # Only a PENDING payment may have no method yet.
            models.CheckConstraint(
                condition=Q(method__isnull=False) | Q(status="PENDING"),
                name="payment_method_required_unless_pending",
            ),
            # A REFUND always points to the payment it refunds.
            models.CheckConstraint(
                condition=~Q(type="REFUND") | Q(original_payment__isnull=False),
                name="refund_has_original_payment",
            ),
            # fee_type is set if and only if the payment is an EXTRA_FEE.
            models.CheckConstraint(
                condition=(Q(type="EXTRA_FEE") & Q(fee_type__isnull=False))
                | (~Q(type="EXTRA_FEE") & Q(fee_type__isnull=True)),
                name="fee_type_only_for_extra_fee",
            ),
            # Business rule "already paid -> 409" also guaranteed by the database:
            # at most one SUCCEEDED RENTAL and one SUCCEEDED DEPOSIT per reservation,
            # even if two requests arrive at the same time.
            models.UniqueConstraint(
                fields=["reservation_id", "type"],
                condition=Q(status="SUCCEEDED", type__in=["RENTAL", "DEPOSIT"]),
                name="uniq_succeeded_rental_or_deposit_per_reservation",
            ),
            # A payment can be refunded only once.
            models.UniqueConstraint(
                fields=["original_payment"],
                condition=Q(type="REFUND"),
                name="uniq_refund_per_original_payment",
            ),
        ]

    def __str__(self):
        return f"{self.type} {self.amount} {self.currency} [{self.status}]"


class Invoice(models.Model):
    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    number = models.CharField(max_length=20, unique=True)  # INV-2026-000001
    reservation_id = models.UUIDField(db_index=True)
    user_id = models.UUIDField(db_index=True)
    total = models.DecimalField(**AMOUNT_FIELD)
    currency = models.CharField(max_length=3, default="TND")
    issued_at = models.DateTimeField()

    class Meta:
        ordering = ["-issued_at"]

    def __str__(self):
        return self.number


class InvoiceLine(models.Model):
    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    invoice = models.ForeignKey(Invoice, on_delete=models.CASCADE, related_name="lines")
    label = models.CharField(max_length=255)
    type = models.CharField(max_length=10, choices=PaymentType.choices)
    fee_type = models.CharField(max_length=10, choices=ExtraFeeType.choices, null=True, blank=True)
    amount = models.DecimalField(**AMOUNT_FIELD)
    payment = models.ForeignKey(Payment, null=True, blank=True, on_delete=models.PROTECT, related_name="invoice_lines")

    def __str__(self):
        return f"{self.label}: {self.amount}"


class InvoiceCounter(models.Model):
    """One row per year; locked with SELECT ... FOR UPDATE to generate gap-free numbers."""
    year = models.PositiveIntegerField(primary_key=True)
    last_value = models.PositiveIntegerField(default=0)


class ProcessedEvent(models.Model):
    """RabbitMQ consumer idempotency: an eventId already stored here is ignored."""
    event_id = models.UUIDField(primary_key=True)
    type = models.CharField(max_length=64)
    queue = models.CharField(max_length=128)
    processed_at = models.DateTimeField(auto_now_add=True)
