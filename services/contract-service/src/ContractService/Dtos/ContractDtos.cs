using System.ComponentModel.DataAnnotations;
using ContractService.Models;

namespace ContractService.Dtos;

/// <summary>Remise des clés : crée le contrat et l'état des lieux de départ.</summary>
public class CreateContractRequest
{
    /// <summary>UUID de la réservation (doit être CONFIRMED, caution payée).</summary>
    /// <example>33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8</example>
    [Required]
    public Guid? ReservationId { get; set; }

    /// <summary>Kilométrage au départ.</summary>
    /// <example>28750</example>
    [Required, Range(0, 2_000_000)]
    public int? Mileage { get; set; }

    /// <summary>Niveau de carburant au départ, entre 0 (vide) et 1 (plein).</summary>
    /// <example>1</example>
    [Required, Range(0.0, 1.0)]
    public double? FuelLevel { get; set; }

    /// <summary>Dommages déjà présents au départ (facultatif).</summary>
    /// <example>Rayure légère pare-chocs arrière</example>
    [MaxLength(2000)]
    public string? Damages { get; set; }

    /// <summary>URL des photos de l'état des lieux (facultatif).</summary>
    public List<string>? Photos { get; set; }

    /// <summary>Instant de signature (UTC). Par défaut : maintenant.</summary>
    /// <example>2026-11-20T09:00:00Z</example>
    public DateTime? SignedAt { get; set; }
}

/// <summary>Modification d'un contrat actif.</summary>
public class UpdateContractRequest
{
    /// <summary>Nouvel instant de signature (UTC).</summary>
    /// <example>2026-11-20T09:30:00Z</example>
    [Required]
    public DateTime? SignedAt { get; set; }
}

/// <summary>Retour du véhicule : état des lieux de retour, calcul des frais et clôture.</summary>
public class ReturnVehicleRequest
{
    /// <summary>Kilométrage au retour (doit être supérieur ou égal à celui du départ).</summary>
    /// <example>29400</example>
    [Required, Range(0, 2_000_000)]
    public int? Mileage { get; set; }

    /// <summary>Niveau de carburant au retour, entre 0 et 1.</summary>
    /// <example>0.75</example>
    [Required, Range(0.0, 1.0)]
    public double? FuelLevel { get; set; }

    /// <summary>Nouveaux dommages constatés (facultatif).</summary>
    /// <example>Rétroviseur gauche cassé</example>
    [MaxLength(2000)]
    public string? Damages { get; set; }

    /// <summary>Montant des réparations estimé par l'agent, en TND (0 si aucun dommage).</summary>
    /// <example>0</example>
    [Range(0, 1_000_000)]
    public decimal? DamageFee { get; set; }

    /// <summary>URL des photos de l'état des lieux (facultatif).</summary>
    public List<string>? Photos { get; set; }

    /// <summary>Instant du retour (UTC). Par défaut : maintenant.</summary>
    /// <example>2026-11-25T11:00:00Z</example>
    public DateTime? ReturnedAt { get; set; }
}

/// <summary>Modification d'un état des lieux (dommages et photos).</summary>
public class UpdateInspectionRequest
{
    /// <summary>Description des dommages.</summary>
    /// <example>Rayure légère pare-chocs arrière</example>
    [MaxLength(2000)]
    public string? Damages { get; set; }

    /// <summary>URL des photos.</summary>
    public List<string>? Photos { get; set; }
}

public record InspectionDto(
    Guid Id,
    Guid ContractId,
    InspectionType Type,
    int Mileage,
    double FuelLevel,
    string? Damages,
    IReadOnlyList<string> Photos,
    DateTime InspectedAt);

public record ExtraFeeDto(Guid Id, Guid ContractId, ExtraFeeType Type, decimal Amount);

public record ContractDto(
    Guid Id,
    string Number,
    Guid ReservationId,
    Guid UserId,
    Guid VehicleId,
    DateTime SignedAt,
    ContractStatus Status,
    DateTime? ClosedAt,
    IReadOnlyList<InspectionDto> Inspections,
    IReadOnlyList<ExtraFeeDto> ExtraFees,
    decimal TotalExtraFees);

/// <summary>Résultat du retour : contrat clôturé, frais et caution à libérer.</summary>
public record ReturnSettlementDto(
    ContractDto Contract,
    IReadOnlyList<ExtraFeeDto> ExtraFees,
    decimal TotalExtraFees,
    decimal DepositToRelease,
    string Currency = "TND");

/// <summary>Réponse de GET /internal/contracts/{id} (docs/internal-api.md).</summary>
public record InternalContractDto(
    Guid Id,
    string Number,
    Guid ReservationId,
    Guid UserId,
    Guid VehicleId,
    ContractStatus Status,
    DateTime SignedAt);

public static class DtoMapper
{
    public static InspectionDto ToDto(this Inspection i) =>
        new(i.Id, i.ContractId, i.Type, i.Mileage, i.FuelLevel, i.Damages, i.Photos, AsUtc(i.InspectedAt));

    public static ExtraFeeDto ToDto(this ExtraFee f) => new(f.Id, f.ContractId, f.Type, f.Amount);

    public static ContractDto ToDto(this Contract c) =>
        new(c.Id, c.Number, c.ReservationId, c.UserId, c.VehicleId, AsUtc(c.SignedAt), c.Status,
            c.ClosedAt is null ? null : AsUtc(c.ClosedAt.Value),
            c.Inspections.OrderBy(i => i.Type).Select(i => i.ToDto()).ToList(),
            c.ExtraFees.OrderBy(f => f.Type).Select(f => f.ToDto()).ToList(),
            c.ExtraFees.Sum(f => f.Amount));

    public static InternalContractDto ToInternalDto(this Contract c) =>
        new(c.Id, c.Number, c.ReservationId, c.UserId, c.VehicleId, c.Status, AsUtc(c.SignedAt));

    // PostgreSQL renvoie des dates sans "Kind" : on les marque UTC pour sérialiser avec "Z".
    private static DateTime AsUtc(DateTime d) => DateTime.SpecifyKind(d, DateTimeKind.Utc);
}
