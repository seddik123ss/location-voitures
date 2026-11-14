using System.Reflection;
using System.Text.Json.Serialization;
using ContractService;
using ContractService.Clients;
using ContractService.Data;
using ContractService.Errors;
using ContractService.Messaging;
using ContractService.Security;
using ContractService.Services;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using Microsoft.OpenApi.Models;
using Steeltoe.Common.Http.Discovery;
using Steeltoe.Discovery.Client;

DotEnv.Load();

var builder = WebApplication.CreateBuilder(args);
var isTesting = builder.Environment.IsEnvironment("Testing");

// Config Server (infra/config-repo/rental-contract.yml) : SERVICE_PORT, SERVICE_HOST, EUREKA_URL, KEYCLOAK_ISSUER
// s'ils ne sont ni dans l'environnement ni dans le .env (jamais pendant les tests).
if (!isTesting) ConfigServerEnv.Load();

// Variables d'environnement de l'équipe (docs/conventions.md) -> configuration
var port = Environment.GetEnvironmentVariable("SERVICE_PORT") ?? "8105";
var env = new Dictionary<string, string?>
{
    ["eureka:instance:port"] = port,
    ["eureka:client:serviceUrl"] = WithSlash(Environment.GetEnvironmentVariable("EUREKA_URL")),
    ["eureka:instance:hostName"] = Environment.GetEnvironmentVariable("SERVICE_HOST"),
    ["Keycloak:Issuer"] = Environment.GetEnvironmentVariable("KEYCLOAK_ISSUER"),
    ["Keycloak:ClientId"] = Environment.GetEnvironmentVariable("KEYCLOAK_CLIENT_ID"),
    ["Keycloak:ClientSecret"] = Environment.GetEnvironmentVariable("KEYCLOAK_CLIENT_SECRET"),
    ["RabbitMq:Url"] = Environment.GetEnvironmentVariable("RABBITMQ_URL"),
    ["RemoteServices:UseFakes"] = Environment.GetEnvironmentVariable("USE_FAKE_SERVICES"),
};
builder.Configuration.AddInMemoryCollection(env.Where(e => !string.IsNullOrEmpty(e.Value)));
builder.WebHost.UseUrls($"http://0.0.0.0:{port}");

// Base PostgreSQL : variable DB_URL si présente, sinon appsettings.json
var connectionString = Environment.GetEnvironmentVariable("DB_URL")
    ?? builder.Configuration.GetConnectionString("ContractDb");
builder.Services.AddDbContext<ContractDbContext>(options =>
    options.UseNpgsql(connectionString, o => o.UseQuerySplittingBehavior(QuerySplittingBehavior.SplitQuery)));

// Eureka : enregistrement RENTAL-CONTRACT (adresse host.docker.internal) et découverte des autres services
if (!isTesting)
{
    builder.Services.AddDiscoveryClient(builder.Configuration);
}

// Keycloak : token de service (client credentials) pour les appels /internal
builder.Services.Configure<KeycloakOptions>(builder.Configuration.GetSection("Keycloak"));
builder.Services.AddHttpContextAccessor();
builder.Services.AddHttpClient<ServiceTokenProvider>();
builder.Services.AddTransient<ServiceTokenHandler>();

// Autres services : chacun est appelé pour de vrai via Eureka, ou simulé (données de docs/demo-ids.md).
// USE_FAKE_SERVICES (ou RemoteServices:UseFakes) : true = tous simulés, false ou vide = aucun,
// ou une liste, par exemple "reservation,vehicle" = ces deux-là simulés, payment appelé pour de vrai.
var remote = builder.Configuration.GetSection("RemoteServices");
var fakes = isTesting
    ? FakeSelection.All
    : FakeSelection.Parse(remote["UseFakes"]);
var timeout = TimeSpan.FromSeconds(remote.GetValue("TimeoutSeconds", 5));

void Remote<TClient, TImpl>(string serviceName) where TClient : class where TImpl : class, TClient =>
    builder.Services.AddHttpClient<TClient, TImpl>(c =>
        {
            c.BaseAddress = new Uri($"http://{serviceName}");
            c.Timeout = timeout;
        })
        .AddServiceDiscovery()
        .AddHttpMessageHandler<ServiceTokenHandler>();

if (fakes.Contains("reservation"))
    builder.Services.AddSingleton<IReservationClient, FakeReservationClient>();
else
    Remote<IReservationClient, HttpReservationClient>(remote["ReservationService"] ?? "RENTAL-RESERVATION");

if (fakes.Contains("payment"))
    builder.Services.AddSingleton<IPaymentClient, FakePaymentClient>();
else
    Remote<IPaymentClient, HttpPaymentClient>(remote["PaymentService"] ?? "RENTAL-PAYMENT");

if (fakes.Contains("vehicle"))
    builder.Services.AddSingleton<IVehicleClient, FakeVehicleClient>();
else
    Remote<IVehicleClient, HttpVehicleClient>(remote["VehicleService"] ?? "RENTAL-VEHICLE");

// RabbitMQ : contract-service publie seulement
builder.Services.Configure<RabbitMqOptions>(builder.Configuration.GetSection("RabbitMq"));
builder.Services.AddSingleton<IEventPublisher, RabbitMqEventPublisher>();

// Sécurité : JWT Keycloak, rôles dans la claim "roles"
builder.Services.AddKeycloakJwt(builder.Configuration["Keycloak:Issuer"]!);

// Règles métier
builder.Services.Configure<FeeOptions>(builder.Configuration.GetSection("Fees"));
builder.Services.AddSingleton(TimeProvider.System);
builder.Services.AddSingleton<FeeCalculator>();
builder.Services.AddScoped<ContractManager>();

// JSON en camelCase, enums en texte (ACTIVE, PICKUP, LATE...)
builder.Services.AddControllers()
    .AddJsonOptions(o =>
    {
        o.JsonSerializerOptions.PropertyNamingPolicy = System.Text.Json.JsonNamingPolicy.CamelCase;
        o.JsonSerializerOptions.Converters.Add(new JsonStringEnumConverter());
        o.JsonSerializerOptions.Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping;
    })
    .ConfigureApiBehaviorOptions(o =>
    {
        // Erreurs de validation (400) au format commun de l'équipe
        o.InvalidModelStateResponseFactory = context =>
        {
            var details = context.ModelState
                .Where(e => e.Value?.Errors.Count > 0)
                .SelectMany(e => e.Value!.Errors.Select(err => new ErrorDetail(
                    ToCamelCase(e.Key),
                    string.IsNullOrEmpty(err.ErrorMessage) ? "Valeur invalide" : err.ErrorMessage)))
                .ToList();
            return new BadRequestObjectResult(new ApiError(400, "BAD_REQUEST", "Requête invalide", details));
        };
    });

builder.Services.AddEndpointsApiExplorer();
builder.Services.AddSwaggerGen(o =>
{
    o.SwaggerDoc("v1", new OpenApiInfo
    {
        Title = "contract-service",
        Version = "v1",
        Description = "Contrats de location et retours de véhicules (Rania). " +
                      "Montants en TND avec 3 décimales, dates ISO 8601 en UTC, identifiants UUID. " +
                      "Cliquer sur Authorize et coller un token Keycloak (realm location-voitures)."
    });
    o.AddSecurityDefinition("Bearer", new OpenApiSecurityScheme
    {
        Type = SecuritySchemeType.Http,
        Scheme = "bearer",
        BearerFormat = "JWT",
        Description = "Token Keycloak (sans le mot Bearer)"
    });
    o.OperationFilter<AuthorizeOperationFilter>();
    var xml = Path.Combine(AppContext.BaseDirectory, $"{Assembly.GetExecutingAssembly().GetName().Name}.xml");
    o.IncludeXmlComments(xml);
    o.UseInlineDefinitionsForEnums();
});

var app = builder.Build();

app.Logger.LogInformation("Services simulés : {Fakes}",
    fakes.Count == 0 ? "aucun (appels réels via Eureka)" : string.Join(", ", fakes));

// Applique les migrations au démarrage (crée les tables et les données de démo)
if (!isTesting)
{
    using var scope = app.Services.CreateScope();
    scope.ServiceProvider.GetRequiredService<ContractDbContext>().Database.Migrate();
}

app.UseMiddleware<ErrorHandlingMiddleware>();

app.UseSwagger();
app.UseSwaggerUI();

app.UseAuthentication();
app.UseAuthorization();

app.MapGet("/health", () => Results.Ok(new { status = "UP" }))
    .WithTags("Health")
    .WithSummary("État du service")
    .Produces(StatusCodes.Status200OK)
    .AllowAnonymous();

app.MapControllers();

app.Run();

static string? WithSlash(string? url) => string.IsNullOrEmpty(url) ? null : url.TrimEnd('/') + "/";

static string ToCamelCase(string key) =>
    string.Join('.', key.TrimStart('$', '.').Split('.')
        .Select(p => p.Length == 0 ? p : char.ToLowerInvariant(p[0]) + p[1..]));

// Rend la classe Program visible pour les tests d'intégration
public partial class Program
{
}

/// <summary>Charge le fichier .env du service (non commité) sans écraser les variables déjà définies.</summary>
internal static class DotEnv
{
    public static void Load()
    {
        var dir = new DirectoryInfo(Directory.GetCurrentDirectory());
        for (var i = 0; i < 4 && dir is not null; i++, dir = dir.Parent)
        {
            var file = Path.Combine(dir.FullName, ".env");
            if (!File.Exists(file) || dir.GetFiles("*.sln").Length == 0 && !dir.GetFiles("*.csproj").Any()) continue;

            foreach (var line in File.ReadAllLines(file))
            {
                var trimmed = line.Trim();
                if (trimmed.Length == 0 || trimmed.StartsWith('#')) continue;
                var eq = trimmed.IndexOf('=');
                if (eq <= 0) continue;
                var key = trimmed[..eq].Trim();
                var value = trimmed[(eq + 1)..].Trim().Trim('"');
                if (Environment.GetEnvironmentVariable(key) is null && value.Length > 0)
                    Environment.SetEnvironmentVariable(key, value);
            }
            return;
        }
    }
}
