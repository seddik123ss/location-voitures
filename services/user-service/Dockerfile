# Image de user-service (même modèle que infra/api-gateway) :
# étape 1 = compilation Maven, étape 2 = JRE seul avec le jar.
# Lancement normal : infra/docker-compose.yml, profil "services" (variables d'environnement fournies par compose).
FROM maven:3.9-eclipse-temurin-17 AS build
WORKDIR /app
COPY pom.xml .
COPY src ./src
RUN mvn -q -DskipTests package

FROM eclipse-temurin:17-jre
COPY --from=build /app/target/*.jar /app.jar
EXPOSE 8101
# Forme "exec" : java reçoit SIGTERM (docker stop) et retire l'instance d'Eureka avant de s'arrêter.
ENTRYPOINT ["java", "-jar", "/app.jar"]
