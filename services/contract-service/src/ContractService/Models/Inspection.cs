namespace ContractService.Models;

/// <summary>
/// État des lieux du véhicule, au départ (PICKUP) ou au retour (RETURN).
/// </summary>
public class Inspection
{
    public Guid Id { get; set; }

    public Guid ContractId { get; set; }
    public Contract? Contract { get; set; }

    public InspectionType Type { get; set; }

    /// <summary>Kilométrage relevé au compteur.</summary>
    public int Mileage { get; set; }

    /// <summary>Niveau de carburant entre 0 (vide) et 1 (plein).</summary>
    public double FuelLevel { get; set; }

    /// <summary>Description des dommages constatés (null si aucun).</summary>
    public string? Damages { get; set; }

    /// <summary>URL des photos prises pendant l'inspection.</summary>
    public List<string> Photos { get; set; } = new();

    /// <summary>Instant de l'inspection (UTC).</summary>
    public DateTime InspectedAt { get; set; }
}
