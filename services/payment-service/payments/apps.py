from django.apps import AppConfig


class PaymentsConfig(AppConfig):
    default_auto_field = "django.db.models.BigAutoField"
    name = "payments"

    def ready(self):
        # Registers the drf-spectacular authentication extensions.
        from . import schema  # noqa: F401
        # Eureka registration (only for the process serving HTTP, see payments/eureka.py).
        from . import eureka
        eureka.start()
