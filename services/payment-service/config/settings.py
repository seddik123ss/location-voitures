"""
Django settings for payment-service.

All environment-specific values come from environment variables (see .env.example),
following docs/conventions.md: SERVICE_PORT, DB_URL, EUREKA_URL, KEYCLOAK_*, RABBITMQ_URL.
"""
import os
from pathlib import Path

import dj_database_url
from dotenv import load_dotenv

BASE_DIR = Path(__file__).resolve().parent.parent

# Load services/payment-service/.env if present (never committed).
load_dotenv(BASE_DIR / ".env")


def env_bool(name: str, default: bool = False) -> bool:
    return os.getenv(name, str(default)).strip().lower() in {"1", "true", "yes", "on"}


SECRET_KEY = os.getenv("DJANGO_SECRET_KEY", "dev-only-insecure-key-change-me")
DEBUG = env_bool("DJANGO_DEBUG", True)
ALLOWED_HOSTS = [h.strip() for h in os.getenv("DJANGO_ALLOWED_HOSTS", "*").split(",") if h.strip()]

SERVICE_NAME = "RENTAL-PAYMENT"
SERVICE_PORT = int(os.getenv("SERVICE_PORT", "8104"))

INSTALLED_APPS = [
    "django.contrib.admin",
    "django.contrib.auth",
    "django.contrib.contenttypes",
    "django.contrib.sessions",
    "django.contrib.messages",
    "django.contrib.staticfiles",
    "rest_framework",
    "drf_spectacular",
    "payments",
]

MIDDLEWARE = [
    "payments.correlation.CorrelationIdMiddleware",
    "django.middleware.security.SecurityMiddleware",
    "django.contrib.sessions.middleware.SessionMiddleware",
    "django.middleware.common.CommonMiddleware",
    "django.middleware.csrf.CsrfViewMiddleware",
    "django.contrib.auth.middleware.AuthenticationMiddleware",
    "django.contrib.messages.middleware.MessageMiddleware",
    "django.middleware.clickjacking.XFrameOptionsMiddleware",
]

ROOT_URLCONF = "config.urls"

TEMPLATES = [
    {
        "BACKEND": "django.template.backends.django.DjangoTemplates",
        "DIRS": [],
        "APP_DIRS": True,
        "OPTIONS": {
            "context_processors": [
                "django.template.context_processors.request",
                "django.contrib.auth.context_processors.auth",
                "django.contrib.messages.context_processors.messages",
            ],
        },
    },
]

WSGI_APPLICATION = "config.wsgi.application"

# DB_URL example: postgres://payment_svc:payment_pwd@localhost:5432/payment_db
DATABASES = {
    "default": dj_database_url.parse(
        os.getenv("DB_URL", "postgres://payment_svc:payment_pwd@localhost:5432/payment_db"),
        conn_max_age=60,
    )
}

AUTH_PASSWORD_VALIDATORS = []

LANGUAGE_CODE = "en-us"
TIME_ZONE = "UTC"
USE_I18N = True
USE_TZ = True  # instants stored and returned in UTC (ISO 8601 with "Z")

STATIC_URL = "static/"
DEFAULT_AUTO_FIELD = "django.db.models.BigAutoField"

# --- Django REST Framework -------------------------------------------------
REST_FRAMEWORK = {
    # camelCase JSON in and out (docs/conventions.md)
    "DEFAULT_RENDERER_CLASSES": (
        "djangorestframework_camel_case.render.CamelCaseJSONRenderer",
        "djangorestframework_camel_case.render.CamelCaseBrowsableAPIRenderer",
    ),
    "DEFAULT_PARSER_CLASSES": (
        "djangorestframework_camel_case.parser.CamelCaseJSONParser",
    ),
    "JSON_UNDERSCOREIZE": {"no_underscore_before_number": True},
    "DEFAULT_SCHEMA_CLASS": "drf_spectacular.openapi.AutoSchema",
    "COERCE_DECIMAL_TO_STRING": False,  # amounts as JSON numbers
    "DATETIME_FORMAT": "%Y-%m-%dT%H:%M:%SZ",
    # Common error format {"status","error","message","details"} for every error.
    "EXCEPTION_HANDLER": "payments.exceptions.api_exception_handler",
    # Common pagination ?page=0&size=20 -> {"items","page","size","total"}.
    "DEFAULT_PAGINATION_CLASS": "payments.pagination.PageSizePagination",
    # Who is calling: see payments/auth.py and AUTH_MODE below.
    "DEFAULT_AUTHENTICATION_CLASSES": [],
    # Every view declares its roles; anything forgotten is closed by default.
    "DEFAULT_PERMISSION_CLASSES": ["rest_framework.permissions.IsAuthenticated"],
    "UNAUTHENTICATED_USER": None,
}

# AUTH_MODE=dev : X-User-Id / X-User-Roles headers (local development only, before Keycloak).
# AUTH_MODE=jwt : Keycloak JWT (step 3).
AUTH_MODE = os.getenv("AUTH_MODE", "dev").lower()
if AUTH_MODE == "dev":
    REST_FRAMEWORK["DEFAULT_AUTHENTICATION_CLASSES"] = ["payments.auth.DevHeaderAuthentication"]
else:
    raise RuntimeError(f"AUTH_MODE={AUTH_MODE!r} not supported yet (dev only until step 3)")

# "log" (step 2: events only logged) | "rabbitmq" (step 3)
EVENT_PUBLISHER = os.getenv("EVENT_PUBLISHER", "log")

LOGGING = {
    "version": 1,
    "disable_existing_loggers": False,
    "formatters": {"simple": {"format": "%(asctime)s %(levelname)s %(name)s %(message)s"}},
    "handlers": {"console": {"class": "logging.StreamHandler", "formatter": "simple"}},
    "loggers": {"payments": {"handlers": ["console"], "level": os.getenv("LOG_LEVEL", "INFO")}},
}

SPECTACULAR_SETTINGS = {
    "TITLE": "payment-service",
    "DESCRIPTION": "Payments and invoices for the car rental platform (RENTAL-PAYMENT).",
    "VERSION": "0.1.0",
    "SERVE_INCLUDE_SCHEMA": False,
    "CAMELIZE_NAMES": True,
    "POSTPROCESSING_HOOKS": [
        "drf_spectacular.hooks.postprocess_schema_enums",
        "drf_spectacular.contrib.djangorestframework_camel_case.camelize_serializer_fields",
    ],
    "COMPONENT_SPLIT_REQUEST": True,
    "ENUM_NAME_OVERRIDES": {
        "PaymentMethodEnum": "payments.models.PaymentMethod",
        "PaymentTypeEnum": "payments.models.PaymentType",
        "PaymentStatusEnum": "payments.models.PaymentStatus",
        "ExtraFeeTypeEnum": "payments.models.ExtraFeeType",
        "PayableTypeEnum": ["RENTAL", "DEPOSIT"],
        "SettleMethodEnum": ["CARD", "CASH"],
        "PaymentDecisionEnum": ["SUCCEEDED", "FAILED"],
    },
}

# --- Other services / infrastructure (used in later steps) -------------------
EUREKA_URL = os.getenv("EUREKA_URL", "http://localhost:8761/eureka")
KEYCLOAK_ISSUER = os.getenv("KEYCLOAK_ISSUER", "http://localhost:8180/realms/location-voitures")
KEYCLOAK_CLIENT_ID = os.getenv("KEYCLOAK_CLIENT_ID", "payment-service")
KEYCLOAK_CLIENT_SECRET = os.getenv("KEYCLOAK_CLIENT_SECRET", "")
RABBITMQ_URL = os.getenv("RABBITMQ_URL", "amqp://guest:guest@localhost:5672/%2F")

# Simulated reservation-service client until RENTAL-RESERVATION is available.
RESERVATION_CLIENT = os.getenv("RESERVATION_CLIENT", "fake")  # "fake" | "http"
