# Image de contract-service (ASP.NET Core 8) : étape 1 = SDK (dotnet publish), étape 2 = runtime ASP.NET seul.
# Lancement normal : infra/docker-compose.yml, profil "services" (variables d'environnement fournies par compose).
# Au démarrage : migrations EF Core (Program.cs), inscription RENTAL-CONTRACT dans Eureka (Steeltoe),
# consommateur RabbitMQ dans le même processus.
FROM mcr.microsoft.com/dotnet/sdk:8.0 AS build
WORKDIR /src
# Restauration d'abord (couche en cache tant que le .csproj ne change pas)
COPY src/ContractService/ContractService.csproj src/ContractService/
RUN dotnet restore src/ContractService/ContractService.csproj
COPY src/ContractService/ src/ContractService/
RUN dotnet publish src/ContractService/ContractService.csproj -c Release -o /app --no-restore

FROM mcr.microsoft.com/dotnet/aspnet:8.0
WORKDIR /app
COPY --from=build /app .
ENV SERVICE_PORT=8105
# Utilisateur non root fourni par l'image .NET 8
USER app
EXPOSE 8105
# Forme "exec" : dotnet reçoit SIGTERM (docker stop) et Steeltoe retire l'instance d'Eureka.
ENTRYPOINT ["dotnet", "ContractService.dll"]
