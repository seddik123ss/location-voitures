from django.contrib import admin

from .models import Invoice, InvoiceLine, Payment, ProcessedEvent


@admin.register(Payment)
class PaymentAdmin(admin.ModelAdmin):
    list_display = ("id", "reservation_id", "type", "method", "amount", "status", "created_at")
    list_filter = ("type", "method", "status")
    search_fields = ("reservation_id", "user_id", "transaction_ref", "idempotency_key")


class InvoiceLineInline(admin.TabularInline):
    model = InvoiceLine
    extra = 0


@admin.register(Invoice)
class InvoiceAdmin(admin.ModelAdmin):
    list_display = ("number", "reservation_id", "total", "issued_at")
    inlines = [InvoiceLineInline]


admin.site.register(ProcessedEvent)
