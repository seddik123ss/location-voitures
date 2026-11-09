using ContractService.Models;
using Microsoft.Extensions.Options;

namespace ContractService.Services;

/// <summary>
/// Calcule les frais au retour : retard (par jour entamé), carburant manquant,
/// kilomètres au-delà du forfait, dommages.
/// </summary>
public class FeeCalculator
{
    private readonly FeeOptions _options;

    public FeeCalculator(IOptions<FeeOptions> options)
    {
        _options = options.Value;
    }

    public record FeeInput(
        DateOnly StartDate,
        DateOnly EndDate,
        DateTime ReturnedAt,
        decimal DailyRate,
        int PickupMileage,
        int ReturnMileage,
        double PickupFuel,
        double ReturnFuel,
        decimal DamageFee);

    public List<(ExtraFeeType Type, decimal Amount)> Calculate(FeeInput input)
    {
        var fees = new List<(ExtraFeeType, decimal)>();

        // Retard : chaque jour entamé après l'heure limite du dernier jour est facturé au tarif journalier
        var deadline = input.EndDate.ToDateTime(new TimeOnly(_options.ReturnDeadlineHourUtc, 0), DateTimeKind.Utc);
        if (input.ReturnedAt > deadline)
        {
            var lateDays = (int)Math.Ceiling((input.ReturnedAt - deadline).TotalHours / 24.0);
            fees.Add((ExtraFeeType.LATE, Round(lateDays * input.DailyRate)));
        }

        // Carburant : part du réservoir manquante x prix d'un plein
        var missingFuel = input.PickupFuel - input.ReturnFuel;
        if (missingFuel > 0.0001)
        {
            fees.Add((ExtraFeeType.FUEL, Round((decimal)missingFuel * _options.FullTankPrice)));
        }

        // Kilométrage : forfait = jours réservés x km inclus par jour
        var rentalDays = Math.Max(1, input.EndDate.DayNumber - input.StartDate.DayNumber);
        var includedKm = rentalDays * _options.IncludedKmPerDay;
        var drivenKm = input.ReturnMileage - input.PickupMileage;
        if (drivenKm > includedKm)
        {
            fees.Add((ExtraFeeType.MILEAGE, Round((drivenKm - includedKm) * _options.ExtraKmPrice)));
        }

        // Dommages : montant estimé par l'agent
        if (input.DamageFee > 0)
        {
            fees.Add((ExtraFeeType.DAMAGE, Round(input.DamageFee)));
        }

        return fees;
    }

    private static decimal Round(decimal amount) => Math.Round(amount, 3, MidpointRounding.AwayFromZero);
}
