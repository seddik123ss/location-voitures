namespace ContractService.Services;

/// <summary>
/// Paramètres du calcul des frais au retour (section "Fees" de appsettings.json).
/// </summary>
public class FeeOptions
{
    /// <summary>Heure limite de retour (UTC) le dernier jour de la réservation.</summary>
    public int ReturnDeadlineHourUtc { get; set; } = 12;

    /// <summary>Prix d'un réservoir plein en TND (frais carburant = part manquante x ce prix).</summary>
    public decimal FullTankPrice { get; set; } = 140.000m;

    /// <summary>Kilomètres inclus par jour de location.</summary>
    public int IncludedKmPerDay { get; set; } = 250;

    /// <summary>Prix d'un kilomètre au-delà du forfait, en TND.</summary>
    public decimal ExtraKmPrice { get; set; } = 0.200m;
}
