using ContractService.Models;
using ContractService.Services;
using Microsoft.Extensions.Options;

namespace ContractService.Tests;

/// <summary>Tests unitaires du calcul des frais au retour.</summary>
public class FeeCalculatorTests
{
    private readonly FeeCalculator _calculator = new(Options.Create(new FeeOptions()));

    [Fact]
    public void Demo_contract_matches_ReturnSettled_example()
    {
        // docs/events/ReturnSettled.json : r-completed, SUV 160 TND/jour, retour 05/10 18:30, 3/4 de plein
        var fees = _calculator.Calculate(new FeeCalculator.FeeInput(
            new DateOnly(2026, 10, 1), new DateOnly(2026, 10, 5),
            new DateTime(2026, 10, 5, 18, 30, 0, DateTimeKind.Utc),
            160.000m, 42500, 42980, 1.0, 0.75, 0m));

        Assert.Equal(2, fees.Count);
        Assert.Contains((ExtraFeeType.LATE, 160.000m), fees);
        Assert.Contains((ExtraFeeType.FUEL, 35.000m), fees);
    }

    [Fact]
    public void Each_started_late_day_is_charged()
    {
        var fees = _calculator.Calculate(new FeeCalculator.FeeInput(
            new DateOnly(2026, 11, 20), new DateOnly(2026, 11, 25),
            new DateTime(2026, 11, 27, 13, 0, 0, DateTimeKind.Utc), // 49 h de retard -> 3 jours
            90.000m, 1000, 1100, 1.0, 1.0, 0m));

        Assert.Equal((ExtraFeeType.LATE, 270.000m), Assert.Single(fees));
    }

    [Fact]
    public void No_fees_when_returned_on_time_full_and_within_allowance()
    {
        var fees = _calculator.Calculate(new FeeCalculator.FeeInput(
            new DateOnly(2026, 11, 20), new DateOnly(2026, 11, 25),
            new DateTime(2026, 11, 25, 12, 0, 0, DateTimeKind.Utc),
            90.000m, 1000, 2250, 1.0, 1.0, 0m));

        Assert.Empty(fees);
    }
}
