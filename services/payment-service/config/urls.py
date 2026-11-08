from django.contrib import admin
from django.urls import include, path
from drf_spectacular.views import SpectacularAPIView, SpectacularSwaggerView

from payments.views import health

urlpatterns = [
    path("health", health, name="health"),
    path("admin/", admin.site.urls),
    # Declared before the router so "openapi.json" is never taken for a payment id.
    path("api/payments/openapi.json", SpectacularAPIView.as_view(), name="schema"),
    path("swagger", SpectacularSwaggerView.as_view(url_name="schema"), name="swagger"),
    path("", include("payments.urls")),
]

# JSON errors in the common format for non-DRF URLs (active when DEBUG=False).
handler404 = "payments.exceptions.json_404"
handler500 = "payments.exceptions.json_500"
