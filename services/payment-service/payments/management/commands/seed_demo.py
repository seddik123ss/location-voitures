"""
python manage.py seed_demo [--reset]

Loads the payments listed in docs/demo-ids.md (same UUIDs as the rest of the team):
- p-confirmed: r-confirmed (client1), RENTAL 450.000 TND, SUCCEEDED, with its invoice;
- p-completed: r-completed (client2), RENTAL 640.000 TND, SUCCEEDED, with its invoice.
Idempotent: running it twice changes nothing. --reset deletes ALL payment data first.
"""
from decimal import Decimal

from django.core.management.base import BaseCommand
from django.db import transaction
from django.utils import timezone

from payments.demo_data import P_COMPLETED, P_CONFIRMED, R_COMPLETED, R_CONFIRMED, RESERVATIONS
from payments.models import Invoice, InvoiceCounter, InvoiceLine, Payment, ProcessedEvent
from payments.numbering import next_invoice_number

DEMO_PAYMENTS = [
    (P_CONFIRMED, R_CONFIRMED, "450.000", "2026-11-08T10:00:00Z"),
    (P_COMPLETED, R_COMPLETED, "640.000", "2026-09-25T10:00:00Z"),
]


class Command(BaseCommand):
    help = "Load the demo payments of docs/demo-ids.md (idempotent)."

    def add_arguments(self, parser):
        parser.add_argument("--reset", action="store_true", help="Delete ALL payment data before seeding.")

    @transaction.atomic
    def handle(self, *args, reset=False, **options):
        if reset:
            InvoiceLine.objects.all().delete()
            Invoice.objects.all().delete()
            Payment.objects.filter(original_payment__isnull=False).delete()  # refunds first (PROTECT)
            Payment.objects.all().delete()
            InvoiceCounter.objects.all().delete()
            ProcessedEvent.objects.all().delete()
            self.stdout.write("All payment data deleted.")

        created = 0
        for payment_id, reservation_id, amount, paid_at in DEMO_PAYMENTS:
            if Payment.objects.filter(pk=payment_id).exists():
                continue
            r = RESERVATIONS[reservation_id]
            payment = Payment.objects.create(
                id=payment_id, reservation_id=reservation_id, user_id=r["user_id"], amount=Decimal(amount),
                method="CARD", type="RENTAL", status="SUCCEEDED",
                transaction_ref=f"SIM-DEMO-{str(payment_id)[:8].upper()}",
                idempotency_key=f"demo-{payment_id}",
            )
            invoice = Invoice.objects.create(
                number=next_invoice_number(), reservation_id=reservation_id, user_id=r["user_id"],
                total=payment.amount, issued_at=timezone.now(),
            )
            InvoiceLine.objects.create(
                invoice=invoice, label=f"Location du {r['start_date']} au {r['end_date']}",
                type="RENTAL", amount=payment.amount, payment=payment,
            )
            created += 1

        self.stdout.write(self.style.SUCCESS(
            f"{created} demo payment(s) created; total: {Payment.objects.count()} payments, "
            f"{Invoice.objects.count()} invoices."
        ))
