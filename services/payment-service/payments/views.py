"""
HTTP layer: parse input, call payments.services, serialize output.
No business rule here (see services.py).
"""
from django.http import JsonResponse
from djangorestframework_camel_case.util import underscoreize
from drf_spectacular.utils import (
    OpenApiExample,
    OpenApiParameter,
    OpenApiResponse,
    extend_schema,
    extend_schema_view,
)
from rest_framework import status, viewsets
from rest_framework.decorators import action
from rest_framework.response import Response

from . import services
from .auth import Role, has_role
from .exceptions import NotFoundError
from .models import Invoice, Payment
from .schema import CORRELATION_PARAM, E400, E401, E403, E404, E502, E503, IDEMPOTENCY_KEY_PARAM, error
from .serializers import (
    InternalPaymentFilterSerializer,
    InternalPaymentSerializer,
    InvoiceFilterSerializer,
    InvoiceSerializer,
    PaymentCreateSerializer,
    PaymentFilterSerializer,
    PaymentPaySerializer,
    PaymentSerializer,
    PaymentUpdateSerializer,
)

UUID_REGEX = r"[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}"
ANY_USER = (Role.CLIENT, Role.AGENT, Role.ADMIN)


def health(request):
    """GET /health -> {"status": "UP"} (common convention, no auth)."""
    return JsonResponse({"status": "UP"})


def _query_filters(request, serializer_class) -> dict:
    """Query params arrive in camelCase (?reservationId=...): convert, then validate (-> 400)."""
    params = underscoreize({k: v for k, v in request.query_params.items() if k not in ("page", "size")})
    serializer = serializer_class(data=params)
    serializer.is_valid(raise_exception=True)
    return serializer.validated_data


def _apply(qs, filters: dict):
    return qs.filter(**{k: v for k, v in filters.items() if v is not None})


# --- Examples ----------------------------------------------------------------

PAYMENT_EXAMPLE = {
    "id": "6f1c2a7e-3b9d-4c1e-9a55-2f7d8e0b1c3a",
    "reservationId": "01cb68ad-4eec-4bdd-8971-ce35381c59d1",
    "userId": "f4d1bab3-468d-428d-b02c-b44341430bfd",
    "amount": 270.0, "currency": "TND", "method": "CARD", "type": "RENTAL", "status": "SUCCEEDED",
    "transactionRef": "SIM-4F2A9C1B7E03", "feeType": None, "failureReason": None, "originalPaymentId": None,
    "createdAt": "2026-11-08T14:30:00Z", "updatedAt": "2026-11-08T14:30:00Z",
}
FILTER_PARAMS = [
    OpenApiParameter("reservationId", str, description="Filtrer par réservation (UUID)."),
    OpenApiParameter("userId", str, description="Filtrer par utilisateur (UUID) ; un CLIENT ne voit que les siens."),
    OpenApiParameter("status", str, enum=["PENDING", "SUCCEEDED", "FAILED", "REFUNDED"]),
    OpenApiParameter("type", str, enum=["RENTAL", "DEPOSIT", "EXTRA_FEE", "REFUND"]),
]


# --- /api/payments -------------------------------------------------------------

@extend_schema(tags=["Paiements"])
@extend_schema_view(
    list=extend_schema(
        summary="Lister les paiements",
        description="CLIENT : uniquement ses paiements. AGENT, ADMIN : tous. Pagination ?page=0&size=20.",
        parameters=FILTER_PARAMS + [CORRELATION_PARAM],
        responses={200: PaymentSerializer(many=True), 400: E400, 401: E401, 403: E403},
    ),
    retrieve=extend_schema(
        summary="Consulter un paiement",
        description="CLIENT : uniquement un de ses paiements (403 sinon). AGENT, ADMIN : tous.",
        responses={200: PaymentSerializer, 401: E401, 403: E403, 404: E404},
        examples=[OpenApiExample("Paiement réussi", value=PAYMENT_EXAMPLE, response_only=True)],
    ),
)
class PaymentViewSet(viewsets.GenericViewSet):
    serializer_class = PaymentSerializer
    queryset = Payment.objects.none()
    lookup_value_regex = UUID_REGEX
    http_method_names = ["get", "post", "patch", "delete", "head", "options"]

    def get_permissions(self):
        if self.action in ("partial_update", "destroy"):
            return [has_role(Role.ADMIN)()]
        return [has_role(*ANY_USER)()]

    def list(self, request):
        qs = _apply(services.payments_visible_to(request.user), _query_filters(request, PaymentFilterSerializer))
        page = self.paginate_queryset(qs)
        return self.get_paginated_response(PaymentSerializer(page, many=True).data)

    def retrieve(self, request, pk=None):
        return Response(PaymentSerializer(services.get_payment_for(request.user, pk)).data)

    @extend_schema(
        summary="Payer une réservation (location ou caution)",
        description=(
            "Rôles : CLIENT (pour sa propre réservation), AGENT, ADMIN.\n\n"
            "- `CARD` : paiement simulé ; un `cardToken` commençant par `fail_` est refusé -> "
            "le paiement est enregistré **FAILED** (réponse 201).\n"
            "- `CASH` : AGENT/ADMIN uniquement, paiement à l'agence -> SUCCEEDED.\n"
            "- `TRANSFER` : reste **PENDING** jusqu'à validation par un ADMIN (PATCH).\n\n"
            "Un paiement RENTAL réussi génère une facture. Tout paiement réussi publie "
            "`PaymentSucceeded` sur `reservation.payment_succeeded`, dans la même transaction "
            "(RabbitMQ indisponible -> 503 et rien n'est enregistré)."
        ),
        parameters=[IDEMPOTENCY_KEY_PARAM, CORRELATION_PARAM],
        request=PaymentCreateSerializer,
        examples=[
            OpenApiExample("Carte acceptée", request_only=True, value={
                "reservationId": "01cb68ad-4eec-4bdd-8971-ce35381c59d1", "type": "RENTAL",
                "method": "CARD", "amount": 270.000, "cardToken": "tok_visa_4242"}),
            OpenApiExample("Carte refusée", request_only=True, value={
                "reservationId": "01cb68ad-4eec-4bdd-8971-ce35381c59d1", "type": "RENTAL",
                "method": "CARD", "amount": 270.000, "cardToken": "fail_insufficient_funds"}),
            OpenApiExample("Caution en espèces (AGENT)", request_only=True, value={
                "reservationId": "01cb68ad-4eec-4bdd-8971-ce35381c59d1", "type": "DEPOSIT",
                "method": "CASH", "amount": 500.000}),
            OpenApiExample("Créé", response_only=True, status_codes=["201"], value=PAYMENT_EXAMPLE),
        ],
        responses={
            201: OpenApiResponse(PaymentSerializer, description="Paiement enregistré (SUCCEEDED, FAILED ou PENDING). En-tête Location."),
            400: E400, 401: E401, 403: E403,
            404: error(404, "Réservation introuvable", "Réservation 9d3f0000-0000-4000-8000-000000000000 introuvable"),
            409: error(409, "Réservation déjà payée, annulée/terminée, ou Idempotency-Key déjà utilisée",
                       "Cette réservation est déjà payée (RENTAL)"),
            422: error(422, "Montant différent du prix attendu", "Le montant ne correspond pas au prix attendu",
                       [{"field": "amount", "message": "Montant attendu : 270.000 TND, reçu : 250.000 TND"}]),
            502: E502, 503: E503,
        },
    )
    def create(self, request):
        body = PaymentCreateSerializer(data=request.data)
        body.is_valid(raise_exception=True)
        payment = services.create_payment(
            request.user, **{"card_token": None, **body.validated_data},
            idempotency_key=request.headers.get(services.IDEMPOTENCY_HEADER),
        )
        return Response(PaymentSerializer(payment).data, status=status.HTTP_201_CREATED,
                        headers={"Location": f"/api/payments/{payment.id}"})

    @extend_schema(
        summary="Modifier un paiement PENDING (ADMIN)",
        description="Seul un paiement **PENDING** peut être modifié (409 sinon). Usage : valider un virement "
                    "(`status=SUCCEEDED` + `transactionRef`) ou le rejeter (`status=FAILED`). La validation "
                    "déclenche la facture (RENTAL) et l'événement PaymentSucceeded.",
        request=PaymentUpdateSerializer,
        examples=[OpenApiExample("Valider un virement", request_only=True,
                                 value={"status": "SUCCEEDED", "transactionRef": "VIR-2026-001234"})],
        responses={200: PaymentSerializer, 400: E400, 401: E401, 403: E403, 404: E404,
                   409: error(409, "Paiement non PENDING ou réservation annulée",
                              "Seul un paiement PENDING peut être modifié (statut actuel : SUCCEEDED)"),
                   422: error(422, "Moyen de paiement manquant pour valider",
                              "Le moyen de paiement est requis pour valider le paiement"),
                   502: E502, 503: E503},
    )
    def partial_update(self, request, pk=None):
        body = PaymentUpdateSerializer(data=request.data)
        body.is_valid(raise_exception=True)
        return Response(PaymentSerializer(services.update_payment(request.user, pk, body.validated_data)).data)

    @extend_schema(
        summary="Supprimer un paiement PENDING ou FAILED (ADMIN)",
        responses={204: OpenApiResponse(description="Supprimé"), 401: E401, 403: E403, 404: E404,
                   409: error(409, "Paiement réussi/remboursé ou présent sur une facture",
                              "Seul un paiement PENDING ou FAILED peut être supprimé (statut : SUCCEEDED)")},
    )
    def destroy(self, request, pk=None):
        services.delete_payment(request.user, pk)
        return Response(status=status.HTTP_204_NO_CONTENT)

    @extend_schema(
        summary="Régler un paiement PENDING (frais de retour, virement non reçu)",
        description="CLIENT : par carte, pour son propre paiement. AGENT/ADMIN : carte ou espèces. "
                    "Carte refusée (`fail_...`) -> 422 et le paiement reste PENDING.",
        parameters=[IDEMPOTENCY_KEY_PARAM, CORRELATION_PARAM],
        request=PaymentPaySerializer,
        examples=[OpenApiExample("Payer par carte", request_only=True,
                                 value={"method": "CARD", "cardToken": "tok_visa_4242"})],
        responses={200: PaymentSerializer, 400: E400, 401: E401, 403: E403, 404: E404,
                   409: error(409, "Paiement non PENDING ou Idempotency-Key déjà utilisée",
                              "Seul un paiement PENDING peut être réglé (statut actuel : SUCCEEDED)"),
                   422: error(422, "Carte refusée", "Carte refusée par la banque (simulation)",
                              [{"field": "cardToken", "message": "Carte refusée"}]),
                   502: E502, 503: E503},
    )
    @action(detail=True, methods=["post"])
    def pay(self, request, pk=None):
        body = PaymentPaySerializer(data=request.data)
        body.is_valid(raise_exception=True)
        payment = services.pay_pending_payment(
            request.user, pk, method=body.validated_data["method"],
            card_token=body.validated_data.get("card_token"),
            idempotency_key=request.headers.get(services.IDEMPOTENCY_HEADER),
        )
        return Response(PaymentSerializer(payment).data)


# --- /api/invoices ---------------------------------------------------------------

@extend_schema(tags=["Factures"])
@extend_schema_view(
    list=extend_schema(
        summary="Lister les factures",
        description="Les factures sont générées automatiquement (paiement RENTAL réussi, frais de retour) "
                    "et ne sont pas modifiables. CLIENT : ses factures ; AGENT, ADMIN : toutes.",
        parameters=[OpenApiParameter("reservationId", str), OpenApiParameter("userId", str), CORRELATION_PARAM],
        responses={200: InvoiceSerializer(many=True), 400: E400, 401: E401, 403: E403},
    ),
    retrieve=extend_schema(
        summary="Consulter une facture et ses lignes",
        responses={200: InvoiceSerializer, 401: E401, 403: E403, 404: E404},
    ),
)
class InvoiceViewSet(viewsets.ReadOnlyModelViewSet):
    serializer_class = InvoiceSerializer
    queryset = Invoice.objects.none()
    lookup_value_regex = UUID_REGEX

    def get_permissions(self):
        return [has_role(*ANY_USER)()]

    def list(self, request, *args, **kwargs):
        qs = _apply(services.invoices_visible_to(request.user), _query_filters(request, InvoiceFilterSerializer))
        page = self.paginate_queryset(qs)
        return self.get_paginated_response(InvoiceSerializer(page, many=True).data)

    def retrieve(self, request, pk=None, *args, **kwargs):
        invoice = Invoice.objects.prefetch_related("lines").filter(pk=pk).first()
        if invoice is None:
            raise NotFoundError(f"Facture {pk} introuvable")
        if not request.user.is_staff_like and invoice.user_id != request.user.user_id:
            from .exceptions import ForbiddenError
            raise ForbiddenError("Cette facture appartient à un autre utilisateur")
        return Response(InvoiceSerializer(invoice).data)


# --- /internal/payments (between services, role INTERNAL) -------------------------

@extend_schema(tags=["Interne"])
@extend_schema_view(
    list=extend_schema(
        summary="[Interne] Paiements d'une réservation",
        description="Réservé aux services (rôle INTERNAL), jamais routé par la Gateway. Utilisé par "
                    "contract-service pour vérifier que la caution est payée "
                    "(`?reservationId=...&type=DEPOSIT&status=SUCCEEDED`). Contrat de docs/internal-api.md : "
                    "**liste simple, non paginée** (une réservation n'a que quelques paiements).",
        parameters=[
            OpenApiParameter("reservationId", str, required=True, description="UUID de la réservation."),
            OpenApiParameter("type", str, enum=["RENTAL", "DEPOSIT", "EXTRA_FEE", "REFUND"]),
            OpenApiParameter("status", str, enum=["PENDING", "SUCCEEDED", "FAILED", "REFUNDED"]),
            CORRELATION_PARAM,
        ],
        responses={200: InternalPaymentSerializer(many=True), 400: E400, 401: E401,
                   403: error(403, "Rôle INTERNAL requis", "Rôle requis : INTERNAL")},
    ),
)
class InternalPaymentViewSet(viewsets.GenericViewSet):
    serializer_class = InternalPaymentSerializer
    queryset = Payment.objects.none()
    pagination_class = None  # plain list, per docs/internal-api.md

    def get_permissions(self):
        return [has_role(Role.INTERNAL)()]

    def list(self, request):
        qs = _apply(Payment.objects.all(), _query_filters(request, InternalPaymentFilterSerializer))
        return Response(InternalPaymentSerializer(qs.order_by("created_at"), many=True).data)
