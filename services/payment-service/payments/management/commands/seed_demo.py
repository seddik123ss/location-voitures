"""
python manage.py seed_demo [--reset]

Loads the payments of docs/demo-ids.md (same UUIDs as the rest of the team):

r-confirmed (client1) - rental and deposit paid, contract-service can hand over the keys:
  p-confirmed          RENTAL   450.000 SUCCEEDED  + invoice
  p-confirmed-deposit  DEPOSIT  500.000 SUCCEEDED

r-completed (client2) - state BEFORE docs/events/ReturnSettled.json:
  p-completed          RENTAL   640.000 SUCCEEDED  + invoice
  p-completed-deposit  DEPOSIT 1000.000 SUCCEEDED
  Publishing ReturnSettled.json then refunds the deposit (REFUND 1000.000, deposit REFUNDED)
  and creates the fees LATE 160.000 + FUEL 35.000 (PENDING) with their invoice.

Idempotent: each payment is created only if its UUID is missing. --reset deletes ALL payment data first.
"""
from datetime import datetime, timezone as dt_timezone
from decimal import Decimal

from django.core.management.base import BaseCommand
from django.db import transaction

from payments import demo_data as d
from payments.models import Invoice, InvoiceCounter, InvoiceLine, Payment, ProcessedEvent
from payments.numbering import next_invoice_number


def at(value: str) -> datetime:
    return datetime.fromisoformat(value).replace(tzinfo=dt_timezone.utc)


# id, reservation, type, amount, date (coherent with the reservation dates); all paid by card, SUCCEEDED
DEMO_PAYMENTS = [
    (d.P_COMPLETED, d.R_COMPLETED, "RENTAL", "640.000", "2026-09-25T10:00:00"),
    (d.P_COMPLETED_DEPOSIT, d.R_COMPLETED, "DEPOSIT", "1000.000", "2026-10-01T09:00:00"),
    (d.P_CONFIRMED, d.R_CONFIRMED, "RENTAL", "450.000", "2026-11-08T10:00:00"),
    (d.P_CONFIRMED_DEPOSIT, d.R_CONFIRMED, "DEPOSIT", "500.000", "2026-11-08T10:05:00"),
]

# One invoice per RENTAL payment, in date order so that invoice numbers follow the dates.
DEMO_INVOICES = [
    (d.P_COMPLETED, "Location du 2026-10-01 au 2026-10-05"),
    (d.P_CONFIRMED, "Location du 2026-11-20 au 2026-11-25"),
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
        for pid, rid, type_, amount, date in DEMO_PAYMENTS:
            if Payment.objects.filter(pk=pid).exists():
                continue
            Payment.objects.create(
                id=pid, reservation_id=rid, user_id=d.RESERVATIONS[rid]["user_id"], amount=Decimal(amount),
                method="CARD", type=type_, status="SUCCEEDED",
                transaction_ref=f"SIM-DEMO-{str(pid)[:8].upper()}", idempotency_key=f"demo-{pid}",
            )
            # created_at/updated_at are automatic: set them afterwards to the demo dates.
            Payment.objects.filter(pk=pid).update(created_at=at(date), updated_at=at(date))
            created += 1

        for pid, label in DEMO_INVOICES:
            if InvoiceLine.objects.filter(payment_id=pid).exists():
                continue
            payment = Payment.objects.get(pk=pid)
            invoice = Invoice.objects.create(
                number=next_invoice_number(payment.created_at), reservation_id=payment.reservation_id,
                user_id=payment.user_id, total=payment.amount, issued_at=payment.created_at,
            )
            InvoiceLine.objects.create(invoice=invoice, label=label, type="RENTAL", amount=payment.amount,
                                       payment=payment)

        self.stdout.write(self.style.SUCCESS(
            f"{created} demo payment(s) created; total: {Payment.objects.count()} payments, "
            f"{Invoice.objects.count()} invoices."
        ))
