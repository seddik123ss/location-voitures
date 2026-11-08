from django.apps import AppConfig


class PaymentsConfig(AppConfig):
    default_auto_field = "django.db.models.BigAutoField"
    name = "payments"

    def ready(self):
        # Registers the drf-spectacular authentication extension.
        from . import schema  # noqa: F401
