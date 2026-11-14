using Xunit;

namespace ContractService.Tests;

/// <summary>Lecture de la configuration non secrète sur le Config Server (réponse simulée, sans réseau).</summary>
public class ConfigServerEnvTests
{
    // Réponse de GET /rental-contract/default : le fichier du service d'abord (le plus prioritaire), puis application.yml.
    private const string Response = """
        {"name":"rental-contract","propertySources":[
          {"name":"file:/config-repo/rental-contract.yml","source":{"service.port":8105,"service.eureka-name":"RENTAL-CONTRACT"}},
          {"name":"file:/config-repo/application.yml","source":{"service.host":"host.docker.internal",
            "eureka.url":"http://localhost:8761/eureka","keycloak.issuer":"http://localhost:8180/realms/location-voitures","service.port":9999}}
        ]}
        """;

    [Fact]
    public void Le_fichier_du_service_l_emporte_sur_application_yml()
    {
        var values = ConfigServerEnv.Extract(Response);

        Assert.Equal("8105", values["SERVICE_PORT"]);
        Assert.Equal("host.docker.internal", values["SERVICE_HOST"]);
        Assert.Equal("http://localhost:8761/eureka", values["EUREKA_URL"]);
        Assert.Equal("http://localhost:8180/realms/location-voitures", values["KEYCLOAK_ISSUER"]);
        Assert.Equal(4, values.Count);
    }

    [Fact]
    public void Url_du_fichier_rental_contract()
    {
        Assert.Equal("http://localhost:8888/rental-contract/default", ConfigServerEnv.Url("http://localhost:8888/"));
    }
}
