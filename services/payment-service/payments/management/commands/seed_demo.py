"""
python manage.py seed_demo [--reset]

Loads the payments of docs/demo-ids.md (same UUIDs as the rest of the team):

r-confirmed (client1) - rental and deposit paid, contract-service can hand over the keys:
  p-confirmed          RENTAL   450.000 SUCCEEDED  + invoice
  p-confirmed-deposit  DEPOSIT  500.000 SUCCEEDED

r-completed (client2) - state after docs/events/ReturnSettled.json (contract closed):
  p-completed          RENTAL   640.000 SUCCEEDED  + invoice
  p-completed-deposit  DEPOSIT 1000.000 REFUNDED   (deposit given back at the return)
  p-completed-refund   REFUND  1000.000 SUCCEEDED  (originalPayment = p-completed-deposit)
  p-completed-late     EXTRA_FEE LATE 160.000 PENDING  } + one invoice for the fees (195.000),
  p-completed-fuel     EXTRA_FEE FUEL  35.000 PENDING  }   the client pays them later
  + the ReturnSettled example eventId recorded as processed (a replay is ignored).

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


# id, reservation, type, method, amount, status, extra fields, date (coherent with the reservation dates)
DEMO_PAYMENTS = [
    (d.P_CONFIRMED, d.R_CONFIRMED, "RENTAL", "CARD", "450.000", "SUCCEEDED", {}, "2026-11-08T10:00:00"),
    (d.P_CONFIRMED_DEPOSIT, d.R_CONFIRMED, "DEPOSIT", "CARD", "500.000", "SUCCEEDED", {}, "2026-11-08T10:05:00"),
    (d.P_COMPLETED, d.R_COMPLETED, "RENTAL", "CARD", "640.000", "SUCCEEDED", {}, "2026-09-25T10:00:00"),
    (d.P_COMPLETED_DEPOSIT, d.R_COMPLETED, "DEPOSIT", "CARD", "1000.000", "REFUNDED", {}, "2026-10-01T09:00:00"),
    (d.P_COMPLETED_DEPOSIT_REFUND, d.R_COMPLETED, "REFUND", "CARD", "1000.000", "SUCCEEDED",
     {"original_payment_id": d.P_COMPLETED_DEPOSIT}, "2026-10-05T18:45:00"),
    (d.P_COMPLETED_FEE_LATE, d.R_COMPLETED, "EXTRA_FEE", None, "160.000", "PENDING",
     {"fee_type": "LATE"}, "2026-10-05T18:45:00"),
    (d.P_COMPLETED_FEE_FUEL, d.R_COMPLETED, "EXTRA_FEE", None, "35.000", "PENDING",
     {"fee_type": "FUEL"}, "2026-10-05T18:45:00"),
]

# (reservation, issued at, [(payment id, label)]) - in date order, so invoice numbers follow the dates
DEMO_INVOICES = [
    (d.R_COMPLETED, "2026-09-25T10:00:00", [(d.P_COMPLETED, "Location du 2026-10-01 au 2026-10-05")]),
    (d.R_COMPLETED, "2026-10-05T18:45:00", [(d.P_COMPLETED_FEE_LATE, "Frais de retour : Retard de restitution"),
                                           (d.P_COMPLETED_FEE_FUEL, "Frais de retour : Carburant")]),
    (d.R_CONFIRMED, "2026-11-08T10:00:00", [(d.P_CONFIRMED, "Location du 2026-11-20 au 2026-11-25")]),
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
        for pid, rid, type_, method, amount, status, extra, date in DEMO_PAYMENTS:
            if Payment.objects.filter(pk=pid).exists():
                continue
            Payment.objects.create(
                id=pid, reservation_id=rid, user_id=d.RESERVATIONS[rid]["user_id"], amount=Decimal(amount),
                method=method, type=type_, status=status,
                transaction_ref=f"SIM-DEMO-{str(pid)[:8].upper()}" if method else None,
                idempotency_key=f"demo-{pid}" if type_ in ("RENTAL", "DEPOSIT") else None,
                **extra,
            )
            # created_at/updated_at are automatic: set them afterwards to the demo dates.
            Payment.objects.filter(pk=pid).update(created_at=at(date), updated_at=at(date))
            created += 1

        for rid, issued, lines in DEMO_INVOICES:
            if InvoiceLine.objects.filter(payment_id=lines[0][0]).exists():
                continue
            payments = {p.id: p for p in Payment.objects.filter(pk__in=[pid for pid, _ in lines])}
            invoice = Invoice.objects.create(
                number=next_invoice_number(at(issued)), reservation_id=rid,
                user_id=d.RESERVATIONS[rid]["user_id"], issued_at=at(issued),
                total=sum((payments[pid].amount for pid, _ in lines), Decimal("0")),
            )
            for pid, label in lines:
                p = payments[pid]
                InvoiceLine.objects.create(invoice=invoice, label=label, type=p.type, fee_type=p.fee_type,
                                           amount=p.amount, payment=p)

        ProcessedEvent.objects.get_or_create(
            event_id=d.RETURN_SETTLED_EXAMPLE_EVENT,
            defaults={"type": "ReturnSettled", "queue": "payment.return_settled"},
        )

        self.stdout.write(self.style.SUCCESS(
            f"{created} demo payment(s) created; total: {Payment.objects.count()} payments, "
            f"{Invoice.objects.count()} invoices."
        ))
