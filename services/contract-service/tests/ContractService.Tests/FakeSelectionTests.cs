using ContractService.Clients;

namespace ContractService.Tests;

/// <summary>Tests du réglage USE_FAKE_SERVICES (simulation service par service).</summary>
public class FakeSelectionTests
{
    [Theory]
    [InlineData("true")]
    [InlineData("TRUE")]
    [InlineData("all")]
    public void True_simulates_all_three(string value) =>
        Assert.Equal(new[] { "payment", "reservation", "vehicle" }, FakeSelection.Parse(value).OrderBy(n => n));

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("false")]
    [InlineData("none")]
    public void False_or_empty_simulates_none(string? value) =>
        Assert.Empty(FakeSelection.Parse(value));

    [Fact]
    public void List_simulates_only_named_services()
    {
        var fakes = FakeSelection.Parse(" reservation , vehicle-service ");

        Assert.Equal(new[] { "reservation", "vehicle" }, fakes.OrderBy(n => n));
        Assert.DoesNotContain("payment", fakes);
    }

    [Fact]
    public void Unknown_name_is_refused_with_a_clear_message()
    {
        var error = Assert.Throws<InvalidOperationException>(() => FakeSelection.Parse("reservation,paiement"));

        Assert.Contains("paiement", error.Message);
    }
}
