namespace ContractService.Clients;

/// <summary>
/// Simulations des services réservation, paiement et véhicule, en attendant qu'ils soient prêts.
/// Données reprises de docs/demo-ids.md. Seront remplacées par des appels via Eureka.
/// </summary>
public static class FakeData
{
    public static readonly Guid Client1 = Guid.Parse("f4d1bab3-468d-428d-b02c-b44341430bfd");
    public static readonly Guid Client2 = Guid.Parse("611f32de-ce7b-4927-b1c4-d113b6479d59");

    public static readonly Guid ReservationPending = Guid.Parse("01cb68ad-4eec-4bdd-8971-ce35381c59d1");
    public static readonly Guid ReservationConfirmed = Guid.Parse("33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8");
    public static readonly Guid ReservationCompleted = Guid.Parse("30eeff32-a413-42fb-9845-8ede4b7f98e0");

    private static readonly VehicleCategoryInfo Economique =
        new(Guid.Parse("94236cd0-16fc-41e0-bfc2-c8b1d2c8b1fc"), "Économique", 90.000m, 500.000m);
    private static readonly VehicleCategoryInfo Suv =
        new(Guid.Parse("a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c"), "SUV", 160.000m, 1000.000m);
    private static readonly VehicleCategoryInfo Luxe =
        new(Guid.Parse("98d95d25-4112-4e52-8fbf-d2d908239b34"), "Luxe", 300.000m, 2000.000m);

    public static readonly IReadOnlyList<VehicleInfo> Vehicles = new List<VehicleInfo>
    {
        new(Guid.Parse("ecdcc60f-ed6a-4007-8622-2b568338ef7a"), "123 TU 4501", "Peugeot", "208", "AVAILABLE", 15200, Economique),
        new(Guid.Parse("9f03ab2d-97a5-4c08-a952-dc6e446370d1"), "124 TU 4502", "Renault", "Clio", "AVAILABLE", 28750, Economique),
        new(Guid.Parse("a1154300-bea1-4072-b750-2b00cf1f0bcd"), "125 TU 4503", "Kia", "Sportage", "AVAILABLE", 42980, Suv),
        new(Guid.Parse("2657e174-f016-4f0c-9485-0c63141432e3"), "126 TU 4504", "Hyundai", "Tucson", "AVAILABLE", 31000, Suv),
        new(Guid.Parse("15073301-d2df-45b0-9055-6b34fe40c07e"), "127 TU 4505", "Mercedes", "Classe C", "AVAILABLE", 12400, Luxe),
        new(Guid.Parse("4d23ae40-9d0d-47e0-a5bc-60dc9690242e"), "128 TU 4506", "Volkswagen", "Polo", "MAINTENANCE", 54300, Economique),
    };

    public static readonly IReadOnlyList<ReservationInfo> Reservations = new List<ReservationInfo>
    {
        new(ReservationPending, Client1, Vehicles[0].Id, new DateOnly(2026, 12, 10), new DateOnly(2026, 12, 13), "PENDING", 270.000m, 500.000m),
        new(ReservationConfirmed, Client1, Vehicles[1].Id, new DateOnly(2026, 11, 20), new DateOnly(2026, 11, 25), "CONFIRMED", 450.000m, 500.000m),
        new(ReservationCompleted, Client2, Vehicles[2].Id, new DateOnly(2026, 10, 1), new DateOnly(2026, 10, 5), "COMPLETED", 640.000m, 1000.000m),
    };

    // Hypothèse à confirmer avec Fadi : la caution est un paiement de type DEPOSIT au statut SUCCEEDED.
    public static readonly IReadOnlyList<PaymentInfo> Payments = new List<PaymentInfo>
    {
        new(Guid.Parse("d76b538e-e367-4bb3-966b-98a4d9dc93a5"), ReservationConfirmed, Client1, 450.000m, "TND", "RENTAL", "SUCCEEDED"),
        new(Guid.Parse("5f2c7a1e-3b8d-4c6f-a9e2-1d0b4c7e8f31"), ReservationConfirmed, Client1, 500.000m, "TND", "DEPOSIT", "SUCCEEDED"),
        new(Guid.Parse("918e07dc-4995-4985-8373-aad04e13636f"), ReservationCompleted, Client2, 640.000m, "TND", "RENTAL", "SUCCEEDED"),
        new(Guid.Parse("6a3d8b2f-4c9e-4d7a-b0f3-2e1c5d8f9a42"), ReservationCompleted, Client2, 1000.000m, "TND", "DEPOSIT", "SUCCEEDED"),
    };
}

public class FakeReservationClient : IReservationClient
{
    public Task<ReservationInfo?> GetReservationAsync(Guid id, CancellationToken ct = default) =>
        Task.FromResult(FakeData.Reservations.FirstOrDefault(r => r.Id == id));
}

public class FakePaymentClient : IPaymentClient
{
    public Task<IReadOnlyList<PaymentInfo>> GetPaymentsByReservationAsync(Guid reservationId, CancellationToken ct = default) =>
        Task.FromResult<IReadOnlyList<PaymentInfo>>(FakeData.Payments.Where(p => p.ReservationId == reservationId).ToList());
}

public class FakeVehicleClient : IVehicleClient
{
    public Task<VehicleInfo?> GetVehicleAsync(Guid id, CancellationToken ct = default) =>
        Task.FromResult(FakeData.Vehicles.FirstOrDefault(v => v.Id == id));
}
