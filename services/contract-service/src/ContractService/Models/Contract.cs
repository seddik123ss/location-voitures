namespace ContractService.Models;

/// <summary>
/// Contrat de location signé à la remise des clés.
/// reservationId, userId et vehicleId sont des UUID d'autres services (pas de clé étrangère entre bases).
/// </summary>
public class Contract
{
    public Guid Id { get; set; }

    /// <summary>Numéro lisible et unique, ex. CTR-2026-0001.</summary>
    public string Number { get; set; } = string.Empty;

    public Guid ReservationId { get; set; }
    public Guid UserId { get; set; }
    public Guid VehicleId { get; set; }

    /// <summary>Instant de signature (UTC).</summary>
    public DateTime SignedAt { get; set; }

    public ContractStatus Status { get; set; } = ContractStatus.ACTIVE;

    /// <summary>Instant de clôture (UTC), rempli au retour du véhicule.</summary>
    public DateTime? ClosedAt { get; set; }

    public List<Inspection> Inspections { get; set; } = new();
    public List<ExtraFee> ExtraFees { get; set; } = new();
}
