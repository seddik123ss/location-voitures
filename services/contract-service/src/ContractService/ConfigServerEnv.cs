using System.Text.Json;

namespace ContractService;

/// <summary>
/// Configuration NON secrète lue au démarrage sur le Config Server (Spring Cloud Config, infra/config-server) :
/// GET {CONFIG_SERVER_URL}/rental-contract/default renvoie infra/config-repo/rental-contract.yml et application.yml.
/// Seules les variables encore absentes sont remplies (appelé juste après DotEnv.Load()) :
/// priorité variable d'environnement ou .env  &gt;  Config Server  &gt;  valeur par défaut (appsettings.json, Program.cs).
/// Les secrets ne viennent jamais du Config Server. CONFIG_SERVER_URL= (vide) désactive l'appel ; jamais en test.
/// </summary>
public static class ConfigServerEnv
{
    /// <summary>Clé du Config Server -> variable d'environnement lue par Program.cs.</summary>
    public static readonly IReadOnlyDictionary<string, string> Keys = new Dictionary<string, string>
    {
        ["service.port"] = "SERVICE_PORT",
        ["service.host"] = "SERVICE_HOST",
        ["eureka.url"] = "EUREKA_URL",
        ["keycloak.issuer"] = "KEYCLOAK_ISSUER",
    };

    private const string Application = "rental-contract";

    public static string Url(string baseUrl) => $"{baseUrl.TrimEnd('/')}/{Application}/default";

    /// <summary>Remplit les variables absentes ; renvoie celles appliquées.</summary>
    public static IReadOnlyDictionary<string, string> Load()
    {
        var baseUrl = Environment.GetEnvironmentVariable("CONFIG_SERVER_URL") ?? "http://localhost:8888";
        if (baseUrl.Length == 0) return new Dictionary<string, string>();

        Dictionary<string, string> values;
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(3) };
            var json = http.GetStringAsync(Url(baseUrl)).GetAwaiter().GetResult();
            values = Extract(json);
        }
        catch (Exception e)
        {
            Console.WriteLine($"[config] Config Server injoignable ({Url(baseUrl)} : {e.Message}) : .env et valeurs par défaut");
            return new Dictionary<string, string>();
        }

        var applied = new Dictionary<string, string>();
        foreach (var (variable, value) in values)
        {
            if (string.IsNullOrEmpty(Environment.GetEnvironmentVariable(variable)))
            {
                Environment.SetEnvironmentVariable(variable, value);
                applied[variable] = value;
            }
        }
        if (applied.Count > 0)
            Console.WriteLine($"[config] {Url(baseUrl)} -> {string.Join(", ", applied.Keys.Order())}");
        return applied;
    }

    /// <summary>propertySources : le fichier du service (le plus prioritaire) avant application.yml.</summary>
    public static Dictionary<string, string> Extract(string json)
    {
        using var document = JsonDocument.Parse(json);
        var merged = new Dictionary<string, string>();
        foreach (var source in document.RootElement.GetProperty("propertySources").EnumerateArray().Reverse())
        {
            foreach (var property in source.GetProperty("source").EnumerateObject())
                merged[property.Name] = property.Value.ValueKind == JsonValueKind.String
                    ? property.Value.GetString()!
                    : property.Value.GetRawText();
        }
        return Keys.Where(k => merged.ContainsKey(k.Key)).ToDictionary(k => k.Value, k => merged[k.Key]);
    }
}
