using ContractService.Models;
using Microsoft.EntityFrameworkCore;

namespace ContractService.Data;

/// <summary>
/// Données de démo, avec les UUID communs de docs/demo-ids.md.
/// c-completed : contrat clôturé de la réservation r-completed (client2, Kia Sportage v3),
/// cohérent avec docs/events/VehicleReturned.json et ReturnSettled.json.
/// </summary>
public static class SeedData
{
    public static readonly Guid ContractCompletedId = Guid.Parse("6804f1c2-925a-4604-94d4-487853bc1cac");
    public static readonly Guid ReservationCompletedId = Guid.Parse("30eeff32-a413-42fb-9845-8ede4b7f98e0");
    public static readonly Guid Client2Id = Guid.Parse("611f32de-ce7b-4927-b1c4-d113b6479d59");
    public static readonly Guid VehicleV3Id = Guid.Parse("a1154300-bea1-4072-b750-2b00cf1f0bcd");

    public static void Apply(ModelBuilder modelBuilder)
    {
        modelBuilder.Entity<Contract>().HasData(new Contract
        {
            Id = ContractCompletedId,
            Number = "CTR-2026-0001",
            ReservationId = ReservationCompletedId,
            UserId = Client2Id,
            VehicleId = VehicleV3Id,
            SignedAt = new DateTime(2026, 10, 1, 9, 0, 0, DateTimeKind.Utc),
            Status = ContractStatus.CLOSED,
            ClosedAt = new DateTime(2026, 10, 5, 18, 45, 0, DateTimeKind.Utc)
        });

        modelBuilder.Entity<Inspection>().HasData(
            new Inspection
            {
                Id = Guid.Parse("5b0e2c1a-7f3d-4e8a-9c21-3d4f5a6b7c80"),
                ContractId = ContractCompletedId,
                Type = InspectionType.PICKUP,
                Mileage = 42500,
                FuelLevel = 1.0,
                Damages = null,
                Photos = new List<string>(),
                InspectedAt = new DateTime(2026, 10, 1, 9, 0, 0, DateTimeKind.Utc)
            },
            new Inspection
            {
                Id = Guid.Parse("8c1f3d2b-6a4e-4f9b-8d32-4e5a6b7c8d91"),
                ContractId = ContractCompletedId,
                Type = InspectionType.RETURN,
                Mileage = 42980,
                FuelLevel = 0.75,
                Damages = null,
                Photos = new List<string>(),
                InspectedAt = new DateTime(2026, 10, 5, 18, 30, 0, DateTimeKind.Utc)
            });

        modelBuilder.Entity<ExtraFee>().HasData(
            new ExtraFee
            {
                Id = Guid.Parse("9d2a4e3c-5b6f-4a0c-9e43-5f6a7b8c9da2"),
                ContractId = ContractCompletedId,
                Type = ExtraFeeType.LATE,
                Amount = 160.000m
            },
            new ExtraFee
            {
                Id = Guid.Parse("ae3b5f4d-4c7a-4b1d-8f54-6a7b8c9daeb3"),
                ContractId = ContractCompletedId,
                Type = ExtraFeeType.FUEL,
                Amount = 35.000m
            });
    }
}
