using System.Net.Http.Headers;
using System.Text.Json;
using ContractService.Errors;

namespace ContractService.Clients;

/// <summary>Paramètres Keycloak du service (client contract-service, rôle INTERNAL).</summary>
public class KeycloakOptions
{
    public string Issuer { get; set; } = "http://localhost:8180/realms/location-voitures";
    public string ClientId { get; set; } = "contract-service";
    public string ClientSecret { get; set; } = string.Empty;
}

/// <summary>
/// Obtient un token de compte de service (client credentials) et le garde en cache jusqu'à son expiration.
/// </summary>
public class ServiceTokenProvider
{
    private readonly HttpClient _http;
    private readonly KeycloakOptions _options;
    private readonly SemaphoreSlim _lock = new(1, 1);
    private string? _token;
    private DateTimeOffset _expiresAt;

    public ServiceTokenProvider(HttpClient http, Microsoft.Extensions.Options.IOptions<KeycloakOptions> options)
    {
        _http = http;
        _options = options.Value;
    }

    public async Task<string> GetTokenAsync(CancellationToken ct)
    {
        if (_token is not null && DateTimeOffset.UtcNow < _expiresAt) return _token;

        await _lock.WaitAsync(ct);
        try
        {
            if (_token is not null && DateTimeOffset.UtcNow < _expiresAt) return _token;

            var form = new FormUrlEncodedContent(new Dictionary<string, string>
            {
                ["grant_type"] = "client_credentials",
                ["client_id"] = _options.ClientId,
                ["client_secret"] = _options.ClientSecret
            });

            HttpResponseMessage response;
            try
            {
                response = await _http.PostAsync($"{_options.Issuer.TrimEnd('/')}/protocol/openid-connect/token", form, ct);
            }
            catch (HttpRequestException)
            {
                throw ApiException.ServiceUnavailable("Keycloak est indisponible");
            }

            using (response)
            {
                if (!response.IsSuccessStatusCode)
                    throw ApiException.ServiceUnavailable(
                        $"Token de service refusé par Keycloak ({(int)response.StatusCode}) : vérifier KEYCLOAK_CLIENT_SECRET");

                using var doc = JsonDocument.Parse(await response.Content.ReadAsStringAsync(ct));
                _token = doc.RootElement.GetProperty("access_token").GetString();
                var expiresIn = doc.RootElement.TryGetProperty("expires_in", out var e) ? e.GetInt32() : 60;
                _expiresAt = DateTimeOffset.UtcNow.AddSeconds(Math.Max(10, expiresIn - 30));
                return _token!;
            }
        }
        finally
        {
            _lock.Release();
        }
    }
}

/// <summary>
/// Ajoute à chaque appel /internal le token de service et retransmet X-Correlation-Id.
/// </summary>
public class ServiceTokenHandler : DelegatingHandler
{
    private readonly ServiceTokenProvider _tokens;
    private readonly IHttpContextAccessor _context;

    public ServiceTokenHandler(ServiceTokenProvider tokens, IHttpContextAccessor context)
    {
        _tokens = tokens;
        _context = context;
    }

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", await _tokens.GetTokenAsync(ct));

        var correlationId = _context.HttpContext?.Request.Headers["X-Correlation-Id"].ToString();
        if (!string.IsNullOrEmpty(correlationId))
            request.Headers.TryAddWithoutValidation("X-Correlation-Id", correlationId);

        return await base.SendAsync(request, ct);
    }
}
