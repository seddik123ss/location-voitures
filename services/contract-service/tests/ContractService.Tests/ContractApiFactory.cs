using System.Security.Claims;
using System.Text.Encodings.Web;
using ContractService.Data;
using ContractService.Messaging;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Diagnostics;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace ContractService.Tests;

/// <summary>
/// Lance l'API en mémoire : base InMemory (pas besoin de Docker), horloge fixe,
/// RabbitMQ remplacé par un enregistreur, Keycloak remplacé par des en-têtes de test.
/// Les autres services restent simulés (FakeData = docs/demo-ids.md).
/// </summary>
public class ContractApiFactory : WebApplicationFactory<Program>
{
    private readonly string _dbName = $"contract-tests-{Guid.NewGuid()}";

    public static readonly DateTimeOffset Now = new(2026, 11, 20, 9, 0, 0, TimeSpan.Zero);

    public RecordingEventPublisher Events { get; } = new();

    protected override void ConfigureWebHost(IWebHostBuilder builder)
    {
        builder.UseEnvironment("Testing");
        builder.ConfigureServices(services =>
        {
            var dbOptions = services.Single(d => d.ServiceType == typeof(DbContextOptions<ContractDbContext>));
            services.Remove(dbOptions);
            services.AddDbContext<ContractDbContext>(o => o
                .UseInMemoryDatabase(_dbName)
                .ConfigureWarnings(w => w.Ignore(InMemoryEventId.TransactionIgnoredWarning)));

            services.AddSingleton<TimeProvider>(new FixedTimeProvider(Now));
            services.AddSingleton<IEventPublisher>(Events);

            // Authentification de test : en-têtes X-Test-User et X-Test-Roles à la place d'un token Keycloak
            services.AddAuthentication(TestAuthHandler.Scheme)
                .AddScheme<AuthenticationSchemeOptions, TestAuthHandler>(TestAuthHandler.Scheme, _ => { });
            services.PostConfigure<AuthenticationOptions>(o =>
            {
                o.DefaultScheme = TestAuthHandler.Scheme;
                o.DefaultAuthenticateScheme = TestAuthHandler.Scheme;
                o.DefaultChallengeScheme = TestAuthHandler.Scheme;
                o.DefaultForbidScheme = TestAuthHandler.Scheme;
            });

            using var scope = services.BuildServiceProvider().CreateScope();
            scope.ServiceProvider.GetRequiredService<ContractDbContext>().Database.EnsureCreated();
        });
    }

    /// <summary>Client HTTP authentifié avec un utilisateur et des rôles donnés.</summary>
    public HttpClient CreateClientAs(Guid userId, params string[] roles)
    {
        var client = CreateClient();
        client.DefaultRequestHeaders.Add("X-Test-User", userId.ToString());
        client.DefaultRequestHeaders.Add("X-Test-Roles", string.Join(',', roles));
        return client;
    }

    private sealed class FixedTimeProvider : TimeProvider
    {
        private readonly DateTimeOffset _now;
        public FixedTimeProvider(DateTimeOffset now) => _now = now;
        public override DateTimeOffset GetUtcNow() => _now;
    }
}

/// <summary>Remplace RabbitMQ : garde en mémoire les messages publiés.</summary>
public class RecordingEventPublisher : IEventPublisher
{
    public List<(string Queue, string Type, object Data)> Published { get; } = new();

    public Task PublishAsync(string queue, string type, object data, DateTime occurredAt, CancellationToken ct = default)
    {
        lock (Published) Published.Add((queue, type, data));
        return Task.CompletedTask;
    }
}

/// <summary>Authentifie à partir des en-têtes X-Test-User / X-Test-Roles (claims "sub" et "roles").</summary>
public class TestAuthHandler : AuthenticationHandler<AuthenticationSchemeOptions>
{
    public const string Scheme = "Test";

    public TestAuthHandler(IOptionsMonitor<AuthenticationSchemeOptions> options, ILoggerFactory logger, UrlEncoder encoder)
        : base(options, logger, encoder)
    {
    }

    protected override Task<AuthenticateResult> HandleAuthenticateAsync()
    {
        if (!Request.Headers.TryGetValue("X-Test-User", out var user))
            return Task.FromResult(AuthenticateResult.NoResult());

        var claims = new List<Claim> { new("sub", user.ToString()) };
        claims.AddRange(Request.Headers["X-Test-Roles"].ToString()
            .Split(',', StringSplitOptions.RemoveEmptyEntries)
            .Select(r => new Claim("roles", r)));
        var identity = new ClaimsIdentity(claims, Scheme, "sub", "roles");
        return Task.FromResult(AuthenticateResult.Success(new AuthenticationTicket(new ClaimsPrincipal(identity), Scheme)));
    }

    protected override Task HandleChallengeAsync(AuthenticationProperties properties)
    {
        Response.StatusCode = 401;
        return Task.CompletedTask;
    }

    protected override Task HandleForbiddenAsync(AuthenticationProperties properties)
    {
        Response.StatusCode = 403;
        return Task.CompletedTask;
    }
}
