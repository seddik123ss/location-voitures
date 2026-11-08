"""
python manage.py seed_demo [--reset]

Loads demo payments/invoices matching payments/demo_data.py:
- R1 (Alice, CONFIRMED): RENTAL + DEPOSIT paid by card, one invoice.
- R2 (Alice, PENDING):   one FAILED card attempt (can still be paid).
- R3 (Bob, CANCELLED):   RENTAL refunded (original REFUNDED + a REFUND payment).
- R4 (Bob, COMPLETED):   RENTAL, DEPOSIT refunded at return, one EXTRA_FEE pending, two invoices.
- R5 (Bob, PENDING):     nothing yet.
Idempotent: running it twice does not duplicate data. --reset deletes ALL payment data first.
"""
from decimal import Decimal

from django.core.management.base import BaseCommand
from django.db import transaction
from django.utils import timezone

from payments.demo_data import R1, R2, R3, R4, RESERVATIONS
from payments.models import Invoice, InvoiceCounter, InvoiceLine, Payment, ProcessedEvent
from payments.numbering import next_invoice_number


class Command(BaseCommand):
    help = "Load demo payments and invoices (idempotent)."

    def add_arguments(self, parser):
        parser.add_argument("--reset", action="store_true", help="Delete all payment data before seeding.")

    @transaction.atomic
    def handle(self, *args, reset=False, **options):
        if reset:
            InvoiceLine.objects.all().delete()
            Invoice.objects.all().delete()
            Payment.objects.filter(type="REFUND").delete()
            Payment.objects.all().delete()
            InvoiceCounter.objects.all().delete()
            ProcessedEvent.objects.all().delete()
            self.stdout.write("All payment data deleted.")

        if Payment.objects.filter(idempotency_key__startswith="demo-").exists():
            self.stdout.write(self.style.WARNING("Demo data already present, nothing to do (use --reset)."))
            return

        def pay(res_id, type_, amount, method="CARD", status="SUCCEEDED", key=None, **extra):
            r = RESERVATIONS[res_id]
            return Payment.objects.create(
                reservation_id=res_id, user_id=r["user_id"], amount=Decimal(amount), method=method,
                type=type_, status=status, idempotency_key=key,
                transaction_ref=extra.pop("transaction_ref", f"SIM-DEMO-{type_[:3]}-{str(res_id)[-4:]}"),
                **extra,
            )

        def invoice(res_id, lines):
            r = RESERVATIONS[res_id]
            inv = Invoice.objects.create(
                number=next_invoice_number(), reservation_id=res_id, user_id=r["user_id"],
                total=sum(Decimal(l["amount"]) for l in lines), issued_at=timezone.now(),
            )
            for l in lines:
                InvoiceLine.objects.create(invoice=inv, **l)
            return inv

        # R1: rental + deposit paid
        p = pay(R1, "RENTAL", "450.000", key="demo-r1-rental")
        pay(R1, "DEPOSIT", "1000.000", key="demo-r1-deposit")
        invoice(R1, [dict(label="Location du 2026-11-10 au 2026-11-13", type="RENTAL", amount=p.amount, payment=p)])

        # R2: failed card attempt
        pay(R2, "RENTAL", "300.000", status="FAILED", key="demo-r2-failed",
            failure_reason="Carte refusée (simulation)")

        # R3: cancelled -> rental refunded
        p = pay(R3, "RENTAL", "200.000", status="REFUNDED", key="demo-r3-rental")
        invoice(R3, [dict(label="Location du 2026-11-20 au 2026-11-22", type="RENTAL", amount=p.amount, payment=p)])
        pay(R3, "REFUND", "200.000", original_payment=p, transaction_ref="SIM-DEMO-REF-0003")

        # R4: completed -> deposit released, one extra fee to pay
        p = pay(R4, "RENTAL", "520.500", key="demo-r4-rental")
        d = pay(R4, "DEPOSIT", "1000.000", status="REFUNDED", key="demo-r4-deposit")
        invoice(R4, [dict(label="Location du 2026-10-01 au 2026-10-05", type="RENTAL", amount=p.amount, payment=p)])
        pay(R4, "REFUND", "1000.000", original_payment=d, transaction_ref="SIM-DEMO-REF-0004")
        fee = pay(R4, "EXTRA_FEE", "35.000", method=None, status="PENDING", fee_type="FUEL", transaction_ref=None)
        invoice(R4, [dict(label="Frais de retour : FUEL", type="EXTRA_FEE", fee_type="FUEL",
                          amount=fee.amount, payment=fee)])

        self.stdout.write(self.style.SUCCESS(
            f"Demo data loaded: {Payment.objects.count()} payments, {Invoice.objects.count()} invoices."
        ))
