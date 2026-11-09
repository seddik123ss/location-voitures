namespace ContractService.Clients;

// Réponses des routes /internal des autres services (voir docs/internal-api.md).

/// <summary>RENTAL-RESERVATION GET /internal/reservations/{id}</summary>
public record ReservationInfo(
    Guid Id,
    Guid UserId,
    Guid VehicleId,
    DateOnly StartDate,
    DateOnly EndDate,
    string Status,
    decimal TotalPrice,
    decimal DepositAmount,
    string Currency = "TND");

/// <summary>RENTAL-PAYMENT GET /internal/payments?reservationId=</summary>
public record PaymentInfo(
    Guid Id,
    Guid ReservationId,
    Guid UserId,
    decimal Amount,
    string Currency,
    string Type,
    string Status);

/// <summary>RENTAL-VEHICLE GET /internal/vehicles/{id}</summary>
public record VehicleInfo(
    Guid Id,
    string Plate,
    string Brand,
    string Model,
    string Status,
    int Mileage,
    VehicleCategoryInfo Category);

public record VehicleCategoryInfo(Guid Id, string Name, decimal DailyRate, decimal Deposit);
