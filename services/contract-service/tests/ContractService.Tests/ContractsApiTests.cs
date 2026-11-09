using System.Net;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using ContractService.Clients;
using ContractService.Data;
using ContractService.Dtos;
using ContractService.Errors;
using ContractService.Models;

namespace ContractService.Tests;

/// <summary>Tests d'intégration de l'API (HTTP -> contrôleur -> règles métier -> base).</summary>
public class ContractsApiTests : IDisposable
{
    private static readonly JsonSerializerOptions Json = new(JsonSerializerDefaults.Web)
    {
        Converters = { new JsonStringEnumConverter() }
    };

    private static readonly Guid Agent1 = Guid.Parse("11c9b774-201e-4a8c-be43-6b084b52f4c7");

    private readonly ContractApiFactory _factory = new();
    private readonly HttpClient _client;

    public ContractsApiTests()
    {
        // Par défaut : agent1 (rôle AGENT)
        _client = _factory.CreateClientAs(Agent1, "AGENT");
    }

    public void Dispose()
    {
        _client.Dispose();
        _factory.Dispose();
    }

    private Task<HttpResponseMessage> CreateContract(Guid reservationId, int mileage = 28750, double fuel = 1.0) =>
        _client.PostAsJsonAsync("/api/contracts", new { reservationId, mileage, fuelLevel = fuel });

    private static async Task<T> Read<T>(HttpResponseMessage response) =>
        (await response.Content.ReadFromJsonAsync<T>(Json))!;

    [Fact]
    public async Task Health_returns_UP()
    {
        var response = await _client.GetAsync("/health");

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Contains("\"status\":\"UP\"", await response.Content.ReadAsStringAsync());
    }

    [Fact]
    public async Task List_returns_demo_contract_paginated()
    {
        var page = await Read<PageResponse<ContractDto>>(await _client.GetAsync("/api/contracts?page=0&size=20"));

        Assert.Equal(1, page.Total);
        Assert.Equal(0, page.Page);
        Assert.Equal(20, page.Size);
        Assert.Equal(SeedData.ContractCompletedId, page.Items[0].Id);
        Assert.Equal(195.000m, page.Items[0].TotalExtraFees);
    }

    [Fact]
    public async Task Get_unknown_contract_returns_404_in_common_format()
    {
        var response = await _client.GetAsync($"/api/contracts/{Guid.NewGuid()}");

        Assert.Equal(HttpStatusCode.NotFound, response.StatusCode);
        var error = await Read<ApiError>(response);
        Assert.Equal(404, error.Status);
        Assert.Equal("NOT_FOUND", error.Error);
    }

    [Fact]
    public async Task Create_on_confirmed_reservation_returns_201_with_location()
    {
        var response = await CreateContract(FakeData.ReservationConfirmed);

        Assert.Equal(HttpStatusCode.Created, response.StatusCode);
        var contract = await Read<ContractDto>(response);
        Assert.NotNull(response.Headers.Location);
        Assert.EndsWith($"/api/contracts/{contract.Id}", response.Headers.Location!.ToString(), StringComparison.OrdinalIgnoreCase);
        Assert.Equal(ContractStatus.ACTIVE, contract.Status);
        Assert.Equal("CTR-2026-0002", contract.Number);
        Assert.Equal(FakeData.Client1, contract.UserId);
        var pickup = Assert.Single(contract.Inspections);
        Assert.Equal(InspectionType.PICKUP, pickup.Type);
    }

    [Fact]
    public async Task Create_on_pending_reservation_returns_409()
    {
        var response = await CreateContract(FakeData.ReservationPending);

        Assert.Equal(HttpStatusCode.Conflict, response.StatusCode);
        Assert.Equal("CONFLICT", (await Read<ApiError>(response)).Error);
    }

    [Fact]
    public async Task Create_on_unknown_reservation_returns_404()
    {
        var response = await CreateContract(Guid.NewGuid());

        Assert.Equal(HttpStatusCode.NotFound, response.StatusCode);
    }

    [Fact]
    public async Task Second_active_contract_for_same_reservation_returns_409()
    {
        Assert.Equal(HttpStatusCode.Created, (await CreateContract(FakeData.ReservationConfirmed)).StatusCode);

        var second = await CreateContract(FakeData.ReservationConfirmed);

        Assert.Equal(HttpStatusCode.Conflict, second.StatusCode);
    }

    [Fact]
    public async Task Create_with_invalid_body_returns_400_with_details()
    {
        var response = await _client.PostAsJsonAsync("/api/contracts",
            new { reservationId = FakeData.ReservationConfirmed, mileage = 100, fuelLevel = 1.5 });

        Assert.Equal(HttpStatusCode.BadRequest, response.StatusCode);
        var error = await Read<ApiError>(response);
        Assert.Contains(error.Details, d => d.Field == "fuelLevel");
    }

    [Fact]
    public async Task Return_with_lower_mileage_returns_422()
    {
        var contract = await Read<ContractDto>(await CreateContract(FakeData.ReservationConfirmed, mileage: 28750));

        var response = await _client.PostAsJsonAsync($"/api/contracts/{contract.Id}/return",
            new { mileage = 28000, fuelLevel = 1.0 });

        Assert.Equal(HttpStatusCode.UnprocessableEntity, response.StatusCode);
        var error = await Read<ApiError>(response);
        Assert.Contains(error.Details, d => d.Field == "mileage");
    }

    [Fact]
    public async Task Return_computes_fees_and_closes_contract()
    {
        // r-confirmed : 2026-11-20 -> 2026-11-25 (5 jours, 1250 km inclus), Renault Clio économique 90 TND/jour
        var contract = await Read<ContractDto>(await CreateContract(FakeData.ReservationConfirmed, mileage: 28750, fuel: 1.0));

        var response = await _client.PostAsJsonAsync($"/api/contracts/{contract.Id}/return", new
        {
            mileage = 30100,            // 1350 km : 100 km de trop -> 20.000
            fuelLevel = 0.5,            // moitié manquante -> 70.000
            damages = "Rétroviseur cassé",
            damageFee = 150,            // -> 150.000
            returnedAt = "2026-11-26T10:00:00Z" // 22 h après la limite (25/11 12:00) -> 1 jour -> 90.000
        });

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var settlement = await Read<ReturnSettlementDto>(response);
        Assert.Equal(ContractStatus.CLOSED, settlement.Contract.Status);
        Assert.Equal(90.000m, settlement.ExtraFees.Single(f => f.Type == ExtraFeeType.LATE).Amount);
        Assert.Equal(70.000m, settlement.ExtraFees.Single(f => f.Type == ExtraFeeType.FUEL).Amount);
        Assert.Equal(20.000m, settlement.ExtraFees.Single(f => f.Type == ExtraFeeType.MILEAGE).Amount);
        Assert.Equal(150.000m, settlement.ExtraFees.Single(f => f.Type == ExtraFeeType.DAMAGE).Amount);
        Assert.Equal(330.000m, settlement.TotalExtraFees);
        Assert.Equal(500.000m, settlement.DepositToRelease);
        Assert.Equal(2, settlement.Contract.Inspections.Count);
    }

    [Fact]
    public async Task Return_on_time_with_full_tank_has_no_fees()
    {
        var contract = await Read<ContractDto>(await CreateContract(FakeData.ReservationConfirmed));

        var settlement = await Read<ReturnSettlementDto>(await _client.PostAsJsonAsync(
            $"/api/contracts/{contract.Id}/return",
            new { mileage = 29500, fuelLevel = 1.0, returnedAt = "2026-11-25T11:00:00Z" }));

        Assert.Empty(settlement.ExtraFees);
        Assert.Equal(0m, settlement.TotalExtraFees);
    }

    [Fact]
    public async Task Return_on_closed_contract_returns_409()
    {
        var response = await _client.PostAsJsonAsync($"/api/contracts/{SeedData.ContractCompletedId}/return",
            new { mileage = 50000, fuelLevel = 1.0 });

        Assert.Equal(HttpStatusCode.Conflict, response.StatusCode);
    }

    [Fact]
    public async Task Update_and_delete_active_contract()
    {
        var contract = await Read<ContractDto>(await CreateContract(FakeData.ReservationConfirmed));

        var updated = await _client.PutAsJsonAsync($"/api/contracts/{contract.Id}", new { signedAt = "2026-11-20T10:30:00Z" });
        Assert.Equal(HttpStatusCode.OK, updated.StatusCode);
        Assert.Equal(new DateTime(2026, 11, 20, 10, 30, 0, DateTimeKind.Utc), (await Read<ContractDto>(updated)).SignedAt);

        Assert.Equal(HttpStatusCode.NoContent, (await _client.DeleteAsync($"/api/contracts/{contract.Id}")).StatusCode);
        Assert.Equal(HttpStatusCode.NotFound, (await _client.GetAsync($"/api/contracts/{contract.Id}")).StatusCode);
    }

    [Fact]
    public async Task Delete_closed_contract_returns_409()
    {
        var response = await _client.DeleteAsync($"/api/contracts/{SeedData.ContractCompletedId}");

        Assert.Equal(HttpStatusCode.Conflict, response.StatusCode);
    }

    [Fact]
    public async Task Inspections_can_be_listed_and_updated_while_contract_active()
    {
        var contract = await Read<ContractDto>(await CreateContract(FakeData.ReservationConfirmed));
        var pickupId = contract.Inspections[0].Id;

        var page = await Read<PageResponse<InspectionDto>>(await _client.GetAsync($"/api/inspections?contractId={contract.Id}"));
        Assert.Equal(1, page.Total);

        var updated = await _client.PutAsJsonAsync($"/api/inspections/{pickupId}",
            new { damages = "Rayure portière", photos = new[] { "https://example.com/p1.jpg" } });
        Assert.Equal(HttpStatusCode.OK, updated.StatusCode);
        Assert.Equal("Rayure portière", (await Read<InspectionDto>(updated)).Damages);
    }

    [Fact]
    public async Task Handover_publishes_VehicleHandedOver()
    {
        await CreateContract(FakeData.ReservationConfirmed, mileage: 28750);

        var evt = Assert.Single(_factory.Events.Published);
        Assert.Equal(("vehicle.contract_events", "VehicleHandedOver"), (evt.Queue, evt.Type));
        Assert.Contains("\"mileage\":28750", JsonSerializer.Serialize(evt.Data));
    }

    [Fact]
    public async Task Return_publishes_VehicleReturned_ReturnSettled_and_ContractClosed()
    {
        var contract = await Read<ContractDto>(await CreateContract(FakeData.ReservationConfirmed));
        _factory.Events.Published.Clear();

        await _client.PostAsJsonAsync($"/api/contracts/{contract.Id}/return",
            new { mileage = 29000, fuelLevel = 0.75, returnedAt = "2026-11-25T11:00:00Z" });

        Assert.Equal(new[]
        {
            ("vehicle.contract_events", "VehicleReturned"),
            ("payment.return_settled", "ReturnSettled"),
            ("reservation.contract_closed", "ContractClosed")
        }, _factory.Events.Published.Select(e => (e.Queue, e.Type)).ToArray());

        var settled = JsonSerializer.Serialize(_factory.Events.Published[1].Data);
        Assert.Contains("\"type\":\"FUEL\"", settled);
        Assert.Contains("\"depositToRelease\":500.000", settled);
    }

    [Fact]
    public async Task Without_token_returns_401()
    {
        var anonymous = _factory.CreateClient();

        Assert.Equal(HttpStatusCode.Unauthorized, (await anonymous.GetAsync("/api/contracts")).StatusCode);
        Assert.Equal(HttpStatusCode.OK, (await anonymous.GetAsync("/health")).StatusCode);
    }

    [Fact]
    public async Task Client_cannot_create_contract_403()
    {
        var client1 = _factory.CreateClientAs(FakeData.Client1, "CLIENT");

        var response = await client1.PostAsJsonAsync("/api/contracts",
            new { reservationId = FakeData.ReservationConfirmed, mileage = 1, fuelLevel = 1.0 });

        Assert.Equal(HttpStatusCode.Forbidden, response.StatusCode);
    }

    [Fact]
    public async Task Client_sees_only_own_contracts()
    {
        await CreateContract(FakeData.ReservationConfirmed); // contrat de client1
        var client1 = _factory.CreateClientAs(FakeData.Client1, "CLIENT");
        var client2 = _factory.CreateClientAs(FakeData.Client2, "CLIENT");

        var page1 = await Read<PageResponse<ContractDto>>(await client1.GetAsync("/api/contracts"));
        Assert.All(page1.Items, c => Assert.Equal(FakeData.Client1, c.UserId));
        Assert.Equal(1, page1.Total);

        // client1 essaie de lire le contrat de démo de client2
        var forbidden = await client1.GetAsync($"/api/contracts/{SeedData.ContractCompletedId}");
        Assert.Equal(HttpStatusCode.Forbidden, forbidden.StatusCode);

        var own = await client2.GetAsync($"/api/contracts/{SeedData.ContractCompletedId}");
        Assert.Equal(HttpStatusCode.OK, own.StatusCode);
    }

    [Fact]
    public async Task Internal_route_requires_INTERNAL_role()
    {
        var response = await _client.GetAsync($"/internal/contracts/{SeedData.ContractCompletedId}");

        Assert.Equal(HttpStatusCode.Forbidden, response.StatusCode);
    }

    [Fact]
    public async Task Internal_route_returns_contract_summary()
    {
        var service = _factory.CreateClientAs(Guid.NewGuid(), "INTERNAL");
        var response = await service.GetAsync($"/internal/contracts/{SeedData.ContractCompletedId}");

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var dto = await Read<InternalContractDto>(response);
        Assert.Equal(SeedData.Client2Id, dto.UserId);
        Assert.Equal(ContractStatus.CLOSED, dto.Status);
    }
}
