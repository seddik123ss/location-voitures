"""
Demo identifiers, copied from docs/demo-ids.md (shared by the whole team: same UUIDs
in every database and in Keycloak). Used by the seed_demo command and by the
simulated reservation client (RESERVATION_CLIENT=fake).
"""
import uuid
from decimal import Decimal

# --- Users (Keycloak realm location-voitures, password = <name>_pwd) ---------
CLIENT1 = uuid.UUID("f4d1bab3-468d-428d-b02c-b44341430bfd")
CLIENT2 = uuid.UUID("611f32de-ce7b-4927-b1c4-d113b6479d59")
AGENT1 = uuid.UUID("11c9b774-201e-4a8c-be43-6b084b52f4c7")
ADMIN1 = uuid.UUID("fd797399-4d3a-433d-afdd-37b0dd3437cb")

# --- Reservations (reservation-service, Aziz) ----------------------------------
R_PENDING = uuid.UUID("01cb68ad-4eec-4bdd-8971-ce35381c59d1")
R_CONFIRMED = uuid.UUID("33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8")
R_COMPLETED = uuid.UUID("30eeff32-a413-42fb-9845-8ede4b7f98e0")

# Same shape as GET /internal/reservations/{id} (snake_case here).
RESERVATIONS = {
    R_PENDING: dict(
        user_id=CLIENT1, vehicle_id=uuid.UUID("ecdcc60f-ed6a-4007-8622-2b568338ef7a"),
        start_date="2026-12-10", end_date="2026-12-13", status="PENDING",
        total_price=Decimal("270.000"), deposit_amount=Decimal("500.000"), currency="TND",
    ),
    R_CONFIRMED: dict(
        user_id=CLIENT1, vehicle_id=uuid.UUID("9f03ab2d-97a5-4c08-a952-dc6e446370d1"),
        start_date="2026-11-20", end_date="2026-11-25", status="CONFIRMED",
        total_price=Decimal("450.000"), deposit_amount=Decimal("500.000"), currency="TND",
    ),
    R_COMPLETED: dict(
        user_id=CLIENT2, vehicle_id=uuid.UUID("a1154300-bea1-4072-b750-2b00cf1f0bcd"),
        start_date="2026-10-01", end_date="2026-10-05", status="COMPLETED",
        total_price=Decimal("640.000"), deposit_amount=Decimal("1000.000"), currency="TND",
    ),
}

# Extra reservation known ONLY by the simulated client (not in docs/demo-ids.md),
# to demonstrate/test the 409 on a cancelled reservation.
R_TEST_CANCELLED = uuid.UUID("0c0c0c0c-0000-4000-8000-00000000c4c1")
TEST_RESERVATIONS = {
    R_TEST_CANCELLED: dict(
        user_id=CLIENT2, vehicle_id=uuid.UUID("15073301-d2df-45b0-9055-6b34fe40c07e"),
        start_date="2026-12-20", end_date="2026-12-22", status="CANCELLED",
        total_price=Decimal("600.000"), deposit_amount=Decimal("2000.000"), currency="TND",
    ),
}

# --- Payments (this service, docs/demo-ids.md) ----------------------------------
# r-confirmed: rental and deposit paid -> contract-service can hand over the keys.
P_CONFIRMED = uuid.UUID("d76b538e-e367-4bb3-966b-98a4d9dc93a5")          # RENTAL 450.000 SUCCEEDED
P_CONFIRMED_DEPOSIT = uuid.UUID("527d092e-2fc3-409c-bb3b-525e074ceb93")  # DEPOSIT 500.000 SUCCEEDED
# r-completed: state after docs/events/ReturnSettled.json (contract c-completed closed).
P_COMPLETED = uuid.UUID("918e07dc-4995-4985-8373-aad04e13636f")          # RENTAL 640.000 SUCCEEDED
P_COMPLETED_DEPOSIT = uuid.UUID("a8a08a06-d754-4c24-804c-bd9b054c203a")  # DEPOSIT 1000.000 REFUNDED
P_COMPLETED_DEPOSIT_REFUND = uuid.UUID("18e87b10-0077-4935-ae0f-c82d51bea6cf")  # REFUND 1000.000 SUCCEEDED
P_COMPLETED_FEE_LATE = uuid.UUID("41a7146a-e36c-4c7c-a69c-08a2f6a0ef02")  # EXTRA_FEE LATE 160.000 PENDING
P_COMPLETED_FEE_FUEL = uuid.UUID("7bd41b82-0e8d-4534-9628-95ee26382a3f")  # EXTRA_FEE FUEL 35.000 PENDING

# eventId of docs/events/ReturnSettled.json: recorded as already processed by seed_demo,
# so replaying the example is ignored (duplicate) instead of billing the fees twice.
RETURN_SETTLED_EXAMPLE_EVENT = uuid.UUID("dbf0d035-f15a-4a96-be2b-035f8b74438f")
