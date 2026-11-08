"""
Fixed demo identifiers, shared by the seed_demo command and (step 2c) the simulated
reservation client, so that demo payments point to reservations the fake client knows.
"""
import uuid
from decimal import Decimal

USER_ALICE = uuid.UUID("11111111-1111-4111-8111-111111111111")
USER_BOB = uuid.UUID("22222222-2222-4222-8222-222222222222")

# id -> reservation as returned by GET /internal/reservations/{id} (snake_case here)
RESERVATIONS = {
    uuid.UUID("aaaaaaaa-0000-4000-8000-000000000001"): dict(
        user_id=USER_ALICE, vehicle_id=uuid.UUID("cccccccc-0000-4000-8000-000000000001"),
        start_date="2026-11-10", end_date="2026-11-13", status="CONFIRMED",
        total_price=Decimal("450.000"), deposit_amount=Decimal("1000.000"), currency="TND",
    ),
    uuid.UUID("aaaaaaaa-0000-4000-8000-000000000002"): dict(
        user_id=USER_ALICE, vehicle_id=uuid.UUID("cccccccc-0000-4000-8000-000000000002"),
        start_date="2026-12-01", end_date="2026-12-03", status="PENDING",
        total_price=Decimal("300.000"), deposit_amount=Decimal("800.000"), currency="TND",
    ),
    uuid.UUID("aaaaaaaa-0000-4000-8000-000000000003"): dict(
        user_id=USER_BOB, vehicle_id=uuid.UUID("cccccccc-0000-4000-8000-000000000003"),
        start_date="2026-11-20", end_date="2026-11-22", status="CANCELLED",
        total_price=Decimal("200.000"), deposit_amount=Decimal("600.000"), currency="TND",
    ),
    uuid.UUID("aaaaaaaa-0000-4000-8000-000000000004"): dict(
        user_id=USER_BOB, vehicle_id=uuid.UUID("cccccccc-0000-4000-8000-000000000004"),
        start_date="2026-10-01", end_date="2026-10-05", status="COMPLETED",
        total_price=Decimal("520.500"), deposit_amount=Decimal("1000.000"), currency="TND",
    ),
    uuid.UUID("aaaaaaaa-0000-4000-8000-000000000005"): dict(
        user_id=USER_BOB, vehicle_id=uuid.UUID("cccccccc-0000-4000-8000-000000000005"),
        start_date="2026-12-15", end_date="2026-12-20", status="PENDING",
        total_price=Decimal("750.000"), deposit_amount=Decimal("1200.000"), currency="TND",
    ),
}

R1, R2, R3, R4, R5 = list(RESERVATIONS)
