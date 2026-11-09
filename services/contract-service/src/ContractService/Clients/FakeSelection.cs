namespace ContractService.Clients;

/// <summary>
/// Lit le réglage USE_FAKE_SERVICES : quels services distants sont simulés.
/// "true" = tous, "false" ou vide = aucun, ou une liste séparée par des virgules :
/// "reservation,vehicle" = réservation et véhicule simulés, paiement appelé pour de vrai.
/// </summary>
public static class FakeSelection
{
    public static readonly IReadOnlyCollection<string> AllNames = new[] { "reservation", "payment", "vehicle" };

    public static IReadOnlySet<string> All => new HashSet<string>(AllNames);

    public static IReadOnlySet<string> Parse(string? value)
    {
        var text = (value ?? string.Empty).Trim().ToLowerInvariant();
        if (text is "" or "false" or "0" or "none") return new HashSet<string>();
        if (text is "true" or "1" or "all") return All;

        var names = text.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Select(n => n.Replace("-service", ""))
            .ToHashSet();

        var unknown = names.Except(AllNames).ToList();
        if (unknown.Count > 0)
            throw new InvalidOperationException(
                $"USE_FAKE_SERVICES : nom inconnu ({string.Join(", ", unknown)}). " +
                "Valeurs possibles : true, false, ou une liste parmi reservation, payment, vehicle.");

        return names;
    }
}
