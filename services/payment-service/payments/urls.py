from rest_framework.routers import SimpleRouter

from .views import InternalPaymentViewSet, InvoiceViewSet, PaymentViewSet

router = SimpleRouter(trailing_slash=False)
router.register("api/payments", PaymentViewSet, basename="payment")
router.register("api/invoices", InvoiceViewSet, basename="invoice")
router.register("internal/payments", InternalPaymentViewSet, basename="internal-payment")

urlpatterns = router.urls
