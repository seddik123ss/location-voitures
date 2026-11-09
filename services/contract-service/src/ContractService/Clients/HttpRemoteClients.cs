using System.Net;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using ContractService.Dtos;
using ContractService.Errors;

namespace ContractService.Clients;

/// <summary>
/// Appels synchrones aux autres services. L'adresse http://NOM-EUREKA est résolue par Steeltoe
/// (jamais d'URL en dur). Traduction des erreurs (docs/conventions.md) :
/// objet introuvable -> null (puis 404), service arrêté ou absent d'Eureka -> 503, réponse illisible -> 502.
/// </summary>
public abstract class RemoteClientBase
{
    protected static readonly JsonSerializerOptions Json = new(JsonSerializerDefaults.Web)
    {
        Converters = { new JsonStringEnumConverter() },
        NumberHandling = JsonNumberHandling.AllowReadingFromString
    };

    private readonly HttpClient _http;
    private readonly string _serviceName;

    protected RemoteClientBase(HttpClient http, string serviceName)
    {
        _http = http;
        _serviceName = serviceName;
    }

    protected async Task<T?> GetAsync<T>(string path, CancellationToken ct) where T : class
    {
        HttpResponseMessage response;
        try
        {
            response = await _http.GetAsync(path, ct);
        }
        catch (HttpRequestException)
        {
            throw ApiException.ServiceUnavailable($"{_serviceName} est indisponible (arrêté ou absent d'Eureka)");
        }
        catch (TaskCanceledException) when (!ct.IsCancellationRequested)
        {
            throw ApiException.ServiceUnavailable($"{_serviceName} ne répond pas (délai dépassé)");
        }

        using (response)
        {
            if (response.StatusCode == HttpStatusCode.NotFound)
                return null;
            if (response.StatusCode == HttpStatusCode.ServiceUnavailable)
                throw ApiException.ServiceUnavailable($"{_serviceName} est indisponible");
            if (!response.IsSuccessStatusCode)
                throw ApiException.BadGateway($"{_serviceName} a répondu {(int)response.StatusCode}");

            try
            {
                return await response.Content.ReadFromJsonAsync<T>(Json, ct)
                       ?? throw ApiException.BadGateway($"Réponse vide de {_serviceName}");
            }
            catch (Exception e) when (e is JsonException or NotSupportedException)
            {
                throw ApiException.BadGateway($"Réponse illisible de {_serviceName}");
            }
        }
    }
}

public class HttpReservationClient : RemoteClientBase, IReservationClient
{
    public HttpReservationClient(HttpClient http) : base(http, "RENTAL-RESERVATION") { }

    public Task<ReservationInfo?> GetReservationAsync(Guid id, CancellationToken ct = default) =>
        GetAsync<ReservationInfo>($"/internal/reservations/{id}", ct);
}

public class HttpVehicleClient : RemoteClientBase, IVehicleClient
{
    public HttpVehicleClient(HttpClient http) : base(http, "RENTAL-VEHICLE") { }

    public Task<VehicleInfo?> GetVehicleAsync(Guid id, CancellationToken ct = default) =>
        GetAsync<VehicleInfo>($"/internal/vehicles/{id}", ct);
}

public class HttpPaymentClient : RemoteClientBase, IPaymentClient
{
    public HttpPaymentClient(HttpClient http) : base(http, "RENTAL-PAYMENT") { }

    public async Task<IReadOnlyList<PaymentInfo>> GetPaymentsByReservationAsync(Guid reservationId, CancellationToken ct = default)
    {
        // docs/internal-api.md : "liste de {...}" ; on accepte un tableau ou une page { items: [...] }
        using var doc = await GetAsync<JsonDocument>($"/internal/payments?reservationId={reservationId}", ct);
        if (doc is null) return Array.Empty<PaymentInfo>();

        try
        {
            var json = doc.RootElement;
            if (json.ValueKind == JsonValueKind.Object && json.TryGetProperty("items", out var items))
                json = items;
            return json.Deserialize<List<PaymentInfo>>(Json) ?? new List<PaymentInfo>();
        }
        catch (JsonException)
        {
            throw ApiException.BadGateway("Réponse illisible de RENTAL-PAYMENT");
        }
    }
}
