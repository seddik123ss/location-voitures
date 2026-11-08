"""
Serializers: input validation (-> 400) and output shape. Field names are snake_case
here; djangorestframework-camel-case converts them to camelCase on the wire.
"""
from decimal import Decimal

from rest_framework import serializers

from .models import Invoice, InvoiceLine, Payment, PaymentMethod, PaymentStatus, PaymentType

AMOUNT = dict(max_digits=12, decimal_places=3, min_value=Decimal("0.001"), coerce_to_string=False)


# --- Output ------------------------------------------------------------------

class PaymentSerializer(serializers.ModelSerializer):
    amount = serializers.DecimalField(**AMOUNT, read_only=True)
    original_payment_id = serializers.UUIDField(read_only=True, allow_null=True)

    class Meta:
        model = Payment
        fields = [
            "id", "reservation_id", "user_id", "amount", "currency", "method", "type", "status",
            "transaction_ref", "fee_type", "failure_reason", "original_payment_id",
            "created_at", "updated_at",
        ]
        read_only_fields = fields


class InvoiceLineSerializer(serializers.ModelSerializer):
    amount = serializers.DecimalField(**AMOUNT, read_only=True)
    payment_id = serializers.UUIDField(read_only=True, allow_null=True)

    class Meta:
        model = InvoiceLine
        fields = ["id", "label", "type", "fee_type", "amount", "payment_id"]
        read_only_fields = fields


class InvoiceSerializer(serializers.ModelSerializer):
    total = serializers.DecimalField(**AMOUNT, read_only=True)
    lines = InvoiceLineSerializer(many=True, read_only=True)

    class Meta:
        model = Invoice
        fields = ["id", "number", "reservation_id", "user_id", "total", "currency", "issued_at", "lines"]
        read_only_fields = fields


# --- Input -------------------------------------------------------------------

class PaymentCreateSerializer(serializers.Serializer):
    reservation_id = serializers.UUIDField(help_text="UUID de la réservation (reservation-service).")
    type = serializers.ChoiceField(
        choices=[PaymentType.RENTAL, PaymentType.DEPOSIT],
        help_text="RENTAL = prix de la location, DEPOSIT = caution.",
    )
    method = serializers.ChoiceField(
        choices=PaymentMethod.choices,
        help_text="CARD (simulée), CASH (agent uniquement), TRANSFER (reste PENDING jusqu'à validation ADMIN).",
    )
    amount = serializers.DecimalField(**AMOUNT, help_text="Montant en TND, 3 décimales ; doit égaler le prix attendu.")
    card_token = serializers.CharField(
        required=False, allow_blank=False, max_length=64, write_only=True,
        help_text="Jeton de carte simulé, obligatoire si method=CARD. Commence par 'fail_' -> paiement refusé. Jamais stocké.",
    )

    def validate(self, attrs):
        if attrs["method"] == PaymentMethod.CARD and not attrs.get("card_token"):
            raise serializers.ValidationError({"card_token": ["Obligatoire pour un paiement par carte."]})
        return attrs


class PaymentPaySerializer(serializers.Serializer):
    method = serializers.ChoiceField(
        choices=[PaymentMethod.CARD, PaymentMethod.CASH],
        help_text="CARD (simulée) ou CASH (agent uniquement).",
    )
    card_token = serializers.CharField(required=False, max_length=64, write_only=True,
                                       help_text="Obligatoire si method=CARD ; 'fail_...' -> 422.")

    def validate(self, attrs):
        if attrs["method"] == PaymentMethod.CARD and not attrs.get("card_token"):
            raise serializers.ValidationError({"card_token": ["Obligatoire pour un paiement par carte."]})
        return attrs


class PaymentUpdateSerializer(serializers.Serializer):
    status = serializers.ChoiceField(
        choices=[PaymentStatus.SUCCEEDED, PaymentStatus.FAILED], required=False,
        help_text="SUCCEEDED (valider, ex. virement reçu) ou FAILED (rejeter).",
    )
    method = serializers.ChoiceField(choices=PaymentMethod.choices, required=False)
    transaction_ref = serializers.CharField(required=False, allow_null=True, max_length=64)
    failure_reason = serializers.CharField(required=False, allow_null=True, max_length=255)

    def validate(self, attrs):
        if not attrs:
            raise serializers.ValidationError("Aucun champ modifiable fourni.")
        return attrs


class PaymentFilterSerializer(serializers.Serializer):
    """Query parameters of GET /api/payments (validated -> 400 on bad values)."""
    reservation_id = serializers.UUIDField(required=False)
    user_id = serializers.UUIDField(required=False)
    status = serializers.ChoiceField(choices=PaymentStatus.choices, required=False)
    type = serializers.ChoiceField(choices=PaymentType.choices, required=False)


class InternalPaymentFilterSerializer(serializers.Serializer):
    reservation_id = serializers.UUIDField(required=True)
    status = serializers.ChoiceField(choices=PaymentStatus.choices, required=False)
    type = serializers.ChoiceField(choices=PaymentType.choices, required=False)


class InvoiceFilterSerializer(serializers.Serializer):
    reservation_id = serializers.UUIDField(required=False)
    user_id = serializers.UUIDField(required=False)


# --- OpenAPI only -------------------------------------------------------------

class ErrorDetailSerializer(serializers.Serializer):
    field = serializers.CharField(allow_null=True)
    message = serializers.CharField()


class ErrorSerializer(serializers.Serializer):
    status = serializers.IntegerField()
    error = serializers.CharField()
    message = serializers.CharField()
    details = ErrorDetailSerializer(many=True)
