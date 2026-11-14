using System.Security.Claims;
using System.Text.Json;
using ContractService.Errors;
using Microsoft.AspNetCore.Authentication.JwtBearer;
using Microsoft.AspNetCore.Authorization;
using Microsoft.IdentityModel.Protocols;
using Microsoft.IdentityModel.Protocols.OpenIdConnect;
using Microsoft.IdentityModel.Tokens;
using Microsoft.OpenApi.Models;
using Swashbuckle.AspNetCore.SwaggerGen;

namespace ContractService.Security;

/// <summary>Rôles du realm location-voitures (claim "roles" du token).</summary>
public static class Roles
{
    public const string Client = "CLIENT";
    public const string Agent = "AGENT";
    public const string Admin = "ADMIN";
    public const string Internal = "INTERNAL";

    public const string Staff = Agent + "," + Admin;
    public const string AnyUser = Client + "," + Agent + "," + Admin;
}

public static class SecuritySetup
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
        Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping
    };

    /// <summary>
    /// JWT Keycloak : Authority = émetteur, MapInboundClaims = false, rôles dans "roles".
    /// jwksUrl (KEYCLOAK_JWKS, Docker) : clés lues directement à cette adresse (keycloak:8080), car la découverte
    /// OpenID de l'émetteur localhost:8180 n'est pas joignable depuis un conteneur. L'émetteur vérifié ne change pas.
    /// </summary>
    public static IServiceCollection AddKeycloakJwt(this IServiceCollection services, string issuer, string? jwksUrl = null)
    {
        services.AddAuthentication(JwtBearerDefaults.AuthenticationScheme)
            .AddJwtBearer(o =>
            {
                if (string.IsNullOrEmpty(jwksUrl))
                    o.Authority = issuer;
                else
                    o.ConfigurationManager = new ConfigurationManager<OpenIdConnectConfiguration>(
                        jwksUrl, new JwksConfigurationRetriever(issuer), new HttpDocumentRetriever { RequireHttps = false });
                o.RequireHttpsMetadata = false;
                o.MapInboundClaims = false;
                o.TokenValidationParameters.ValidIssuer = issuer;
                o.TokenValidationParameters.ValidateAudience = false; // les tokens Keycloak ont aud = "account"
                o.TokenValidationParameters.RoleClaimType = "roles";
                o.TokenValidationParameters.NameClaimType = "preferred_username";

                // 401 et 403 au format d'erreur commun
                o.Events = new JwtBearerEvents
                {
                    OnChallenge = async ctx =>
                    {
                        ctx.HandleResponse();
                        await WriteError(ctx.Response, 401, "UNAUTHORIZED", "Token absent ou invalide");
                    },
                    OnForbidden = ctx => WriteError(ctx.Response, 403, "FORBIDDEN", "Rôle insuffisant")
                };
            });

        services.AddAuthorization();
        return services;
    }

    private static Task WriteError(HttpResponse response, int status, string error, string message)
    {
        response.StatusCode = status;
        response.ContentType = "application/json";
        return response.WriteAsync(JsonSerializer.Serialize(
            new ApiError(status, error, message, Array.Empty<ErrorDetail>()), JsonOptions));
    }

    /// <summary>UUID de l'utilisateur connecté (claim "sub" = id Keycloak = id de docs/demo-ids.md).</summary>
    public static Guid? UserId(this ClaimsPrincipal user) =>
        Guid.TryParse(user.FindFirst("sub")?.Value, out var id) ? id : null;

    /// <summary>Vrai si l'utilisateur est seulement CLIENT (il ne voit que ses propres contrats).</summary>
    public static bool IsClientOnly(this ClaimsPrincipal user) =>
        !user.IsInRole(Roles.Agent) && !user.IsInRole(Roles.Admin);
}

/// <summary>Configuration OpenID construite à partir du seul JWKS (clés de signature) et de l'émetteur attendu.</summary>
public class JwksConfigurationRetriever : IConfigurationRetriever<OpenIdConnectConfiguration>
{
    private readonly string _issuer;

    public JwksConfigurationRetriever(string issuer) => _issuer = issuer;

    public async Task<OpenIdConnectConfiguration> GetConfigurationAsync(
        string address, IDocumentRetriever retriever, CancellationToken cancel)
    {
        var keys = new JsonWebKeySet(await retriever.GetDocumentAsync(address, cancel));
        var configuration = new OpenIdConnectConfiguration { Issuer = _issuer, JwksUri = address };
        foreach (var key in keys.GetSigningKeys())
            configuration.SigningKeys.Add(key);
        return configuration;
    }
}

/// <summary>Swagger : bouton "Authorize" (token Bearer) et réponses 401/403 sur les routes protégées.</summary>
public class AuthorizeOperationFilter : IOperationFilter
{
    public void Apply(OpenApiOperation operation, OperationFilterContext context)
    {
        var attributes = context.MethodInfo.GetCustomAttributes(true)
            .Concat(context.MethodInfo.DeclaringType?.GetCustomAttributes(true) ?? Array.Empty<object>())
            .ToList();
        if (attributes.OfType<IAllowAnonymous>().Any() || !attributes.OfType<IAuthorizeData>().Any()) return;

        var error = context.SchemaGenerator.GenerateSchema(typeof(ApiError), context.SchemaRepository);
        operation.Responses.TryAdd("401", new OpenApiResponse
        {
            Description = "Token absent ou invalide.",
            Content = { ["application/json"] = new OpenApiMediaType { Schema = error } }
        });
        operation.Responses.TryAdd("403", new OpenApiResponse
        {
            Description = "Rôle insuffisant.",
            Content = { ["application/json"] = new OpenApiMediaType { Schema = error } }
        });
        operation.Security.Add(new OpenApiSecurityRequirement
        {
            [new OpenApiSecurityScheme { Reference = new OpenApiReference { Type = ReferenceType.SecurityScheme, Id = "Bearer" } }] = Array.Empty<string>()
        });

        var roles = attributes.OfType<IAuthorizeData>().Select(a => a.Roles).FirstOrDefault(r => !string.IsNullOrEmpty(r));
        if (roles is not null)
            operation.Description = $"{operation.Description}\n\nRôles autorisés : {roles}".Trim();
    }
}
