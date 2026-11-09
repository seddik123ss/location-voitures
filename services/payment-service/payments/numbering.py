from django.db import transaction
from django.utils import timezone

from .models import InvoiceCounter


def next_invoice_number(now=None) -> str:
    """
    Return the next invoice number, e.g. INV-2026-000001.

    Must be called inside the transaction that creates the invoice: the counter row
    is locked (SELECT ... FOR UPDATE) until commit, so two concurrent invoices can
    never get the same number, and a rollback does not burn a number.
    """
    if not transaction.get_connection().in_atomic_block:
        raise RuntimeError("next_invoice_number() must be called inside transaction.atomic()")
    year = (now or timezone.now()).year
    InvoiceCounter.objects.get_or_create(year=year)
    counter = InvoiceCounter.objects.select_for_update().get(year=year)
    counter.last_value += 1
    counter.save(update_fields=["last_value"])
    return f"INV-{year}-{counter.last_value:06d}"
