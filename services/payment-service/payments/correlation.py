"""
X-Correlation-Id (docs/conventions.md: "to be passed on unchanged").

The middleware reads the incoming header (or generates one), keeps it in a ContextVar
for the duration of the request, and returns it in the response. Outgoing HTTP calls
and published RabbitMQ messages reuse get_correlation_id().
"""
import uuid
from contextvars import ContextVar

HEADER = "X-Correlation-Id"
_META_KEY = "HTTP_X_CORRELATION_ID"

_current: ContextVar[str | None] = ContextVar("correlation_id", default=None)


def get_correlation_id() -> str | None:
    return _current.get()


class CorrelationIdMiddleware:
    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        correlation_id = request.META.get(_META_KEY) or str(uuid.uuid4())
        token = _current.set(correlation_id)
        try:
            response = self.get_response(request)
        finally:
            _current.reset(token)
        response[HEADER] = correlation_id
        return response
